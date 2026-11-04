<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Contracts\StockReserver;
use ShopperPurchaseOrders\Enums\OrderState;
use ShopperPurchaseOrders\Models\Receipt;

final class ConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('PO_TEST_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped(
                'Run with PO_TEST_MYSQL=1 and pcntl against the dedicated MySQL test container.',
            );
        }
        parent::setUp();
    }

    public function test_competing_receipts_cannot_over_receive(): void
    {
        $order = $this->ordered();
        $results = $this->race(
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'a',
                $this->receiveLine($order, 6),
            )->id,
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'b',
                $this->receiveLine($order, 6),
            )->id,
        );
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['status'] === 'ok')));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['status'] === 'rejected')));
        $this->assertSame(6, $this->product->stockInventory($this->inventory->id));
        $this->assertSame(4, $order->fresh('items')->outstandingQuantity());
    }

    public function test_simultaneous_duplicate_requests_share_one_receipt(): void
    {
        $order = $this->ordered();
        $operation = fn () => $this->purchasing->receive(
            $order->id,
            $order->version,
            'same-key',
            $this->receiveLine($order, 6),
        )->id;
        $results = $this->race($operation, $operation);
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['value'], $results[1]['value']);
        $this->assertSame(1, Receipt::query()->count());
        $this->assertSame(6, $this->product->stockInventory($this->inventory->id));
    }

    public function test_receipt_versus_cancellation_has_one_consistent_outcome(): void
    {
        $order = $this->ordered();
        $results = $this->race(
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'a',
                $this->receiveLine($order, 6),
            )->id,
            fn () => $this->purchasing->cancel($order->id, $order->version, 'Supplier cancelled')
                ->id,
        );
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['status'] === 'ok')));
        $order->refresh();
        $stock = $this->product->stockInventory($this->inventory->id);
        $this->assertSame($order->state === OrderState::Cancelled ? 0 : 6, $stock);
    }

    public function test_receiving_coordinates_with_shopper_checkout_reservation(): void
    {
        $order = $this->ordered();
        $results = $this->race(
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'a',
                $this->receiveLine($order, 10),
            )->id,
            fn () => app(StockReserver::class)->reserve($this->product, 8, userId: $this->user->id),
        );
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $reserved = $results[1]['value'];
        $this->assertContains($reserved, [0, 8]);
        $this->assertSame(10 - $reserved, $this->product->stockInventory($this->inventory->id));
    }

    private function race(callable ...$actions): array
    {
        // Close parent sockets before fork: every child creates its own database connection.
        DB::disconnect();
        $children = [];
        foreach ($actions as $action) {
            $path = tempnam(sys_get_temp_dir(), 'shopper-po-race-');
            [$parent, $child] = stream_socket_pair(
                STREAM_PF_UNIX,
                STREAM_SOCK_STREAM,
                STREAM_IPPROTO_IP,
            );
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Unable to fork test worker.');
            }
            if ($pid === 0) {
                fclose($parent);
                stream_set_timeout($child, 20);
                fread($child, 1);
                fclose($child);
                try {
                    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
                    $result = ['status' => 'ok', 'value' => $action()];
                } catch (ValidationException $error) {
                    $result = ['status' => 'rejected', 'message' => $error->getMessage()];
                } catch (\Throwable $error) {
                    $result = [
                        'status' => 'error',
                        'message' => $error::class.': '.$error->getMessage(),
                    ];
                }
                file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit(0);
            }
            fclose($child);
            $children[] = compact('pid', 'path', 'parent');
        }
        foreach ($children as $child) {
            fwrite($child['parent'], 'x');
            fclose($child['parent']);
        }
        $results = [];
        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $result = json_decode(
                file_get_contents($child['path']),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            unlink($child['path']);
            $this->assertNotSame('error', $result['status'], $result['message'] ?? '');
            $results[] = $result;
        }
        DB::reconnect();

        return $results;
    }
}
