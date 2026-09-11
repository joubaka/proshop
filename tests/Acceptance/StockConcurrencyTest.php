<?php

namespace Tests\Acceptance;

use App\BusinessLocation;
use App\Product;
use App\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class StockConcurrencyTest extends \Tests\TestCase
{
    public function createApplication()
    {
        return require dirname(__DIR__, 2).'/scripts/local-acceptance/bootstrap.php';
    }

    public static function changes(): array
    {
        return ['existing receipts' => [10, 2, 3, 15], 'first receipts' => [null, 2, 3, 5],
            'receipt and sale' => [10, 3, -2, 11], 'first sale and receipt' => [null, -2, 3, 1]];
    }

    public function test_two_processes_cannot_charge_or_record_the_same_invoice_twice(): void
    {
        $this->assertTrue(app()->environment('acceptance'));
        $user = User::where('username', 'local.admin')->firstOrFail();
        $location = BusinessLocation::where('business_id', $user->business_id)->firstOrFail()->replicate();
        $location->name = 'Concurrency test '.bin2hex(random_bytes(8));
        $location->save();
        $invoice = \App\Transaction::create(['business_id' => $user->business_id, 'location_id' => $location->id,
            'type' => 'sell', 'status' => 'final', 'payment_status' => 'due', 'created_by' => $user->id,
            'contact_id' => \App\Contact::where('business_id', $user->business_id)->where('is_default', 1)->value('id'),
            'invoice_token' => bin2hex(random_bytes(24)), 'transaction_date' => now(), 'total_before_tax' => 100, 'final_total' => 100]);
        $workers = [];
        $barrier = base_path('.local-acceptance/cache/concurrency-'.bin2hex(random_bytes(12)).'.signal');
        file_put_contents($barrier, 'WAIT');
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec,curl_multi_exec',
                    '-d', 'allow_url_fopen=0', base_path('scripts/local-acceptance/concurrency-worker.php'),
                    (string) $invoice->id, '0', (string) $location->id, '0', $barrier, 'payment'], base_path(), null, null, 30);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $deadline = microtime(true) + 15;
                while (!str_contains($worker->getOutput(), 'READY') && $worker->isRunning() && microtime(true) < $deadline) { usleep(10000); }
                $this->assertStringContainsString('READY', $worker->getOutput(), $worker->getErrorOutput());
            }
            file_put_contents($barrier, 'GO');
            foreach ($workers as $worker) { $this->assertSame(0, $worker->wait(), $worker->getErrorOutput()); }
            $this->assertSame(1, \App\InvoicePaymentAttempt::where('transaction_id', $invoice->id)->count());
            $this->assertSame('completed', \App\InvoicePaymentAttempt::where('transaction_id', $invoice->id)->value('status'));
            $this->assertSame(1, $invoice->payment_lines()->count());
            $this->assertEquals(100, $invoice->payment_lines()->sum('amount'));
            $this->assertSame('paid', $invoice->fresh()->payment_status);
        } finally {
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(1); } }
            unlink($barrier);
            DB::table('activity_log')->where('subject_type', \App\Transaction::class)->where('subject_id', $invoice->id)->delete();
            DB::table('invoice_payment_attempts')->where('transaction_id', $invoice->id)->delete();
            $invoice->payment_lines()->delete();
            $invoice->delete();
            $location->delete();
        }
    }

    #[DataProvider('changes')]
    public function test_two_processes_preserve_stock_without_duplicate_location_rows(?int $initial, int $first, int $second, int $expected): void
    {
        $this->assertTrue(app()->environment('acceptance'));
        $this->assertSame('proshop_acceptance', DB::connection()->getDatabaseName());
        $business = User::where('username', 'local.admin')->value('business_id');
        $product = Product::where('business_id', $business)->where('name', 'Test Tennis Balls')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $location = BusinessLocation::where('business_id', $business)->firstOrFail()->replicate();
        $location->name = 'Concurrency test '.bin2hex(random_bytes(8));
        $location->save();
        $workers = [];
        $barrier = base_path('.local-acceptance/cache/concurrency-'.bin2hex(random_bytes(12)).'.signal');
        file_put_contents($barrier, 'WAIT');
        try {
            if ($initial !== null) {
                (new \App\Utils\ProductUtil)->updateProductQuantity($location->id, $product->id, $variation->id, $initial, 0, null, false);
            }
            foreach ([$first, $second] as $delta) {
                $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec,curl_multi_exec',
                    '-d', 'allow_url_fopen=0', base_path('scripts/local-acceptance/concurrency-worker.php'),
                    (string) $product->id, (string) $variation->id, (string) $location->id, (string) $delta, $barrier], base_path(), null, null, 30);
                $process->start();
                $workers[] = $process;
            }
            foreach ($workers as $worker) {
                $deadline = microtime(true) + 15;
                while (!str_contains($worker->getOutput(), 'READY') && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('READY', $worker->getOutput(), $worker->getErrorOutput());
            }
            file_put_contents($barrier, 'GO');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $this->assertStringContainsString('DONE', $worker->getOutput());
            }
            $rows = DB::table('variation_location_details')->where('location_id', $location->id)->get();
            $this->assertCount(1, $rows);
            $this->assertEquals($expected, $rows[0]->qty_available);
        } finally {
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(1); } }
            unlink($barrier);
            DB::table('variation_location_details')->where('location_id', $location->id)->delete();
            $location->delete();
        }
    }
}
