<?php

namespace Tests\Feature;

use App\AccountTransaction;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegressionTestCase;

class PaymentBalanceRegressionTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Only emulate MySQL IF for this focused query. This does NOT verify MySQL compatibility.
        DB::connection()->getPdo()->sqliteCreateFunction('IF', fn ($condition, $yes, $no) => $condition ? $yes : $no, 3);
        Schema::create('transactions', function ($table) {
            $table->id(); $table->decimal('final_total', 22, 4); $table->string('payment_status')->default('due'); $table->timestamps();
        });
        Schema::create('transaction_payments', function ($table) {
            $table->id(); $table->integer('transaction_id'); $table->decimal('amount', 22, 4); $table->boolean('is_return')->default(false);
        });
        DB::table('transactions')->insert([['id' => 1, 'final_total' => 100], ['id' => 2, 'final_total' => 100]]);
    }

    public static function payments(): array
    {
        return [
            'unpaid' => [[], 'due', 0],
            'partial' => [[[40, false]], 'partial', 40],
            'paid' => [[[100, false]], 'paid', 100],
            'split payment' => [[[60, false], [40, false]], 'paid', 100],
            'change deducted' => [[[120, false], [20, true]], 'paid', 100],
            'refund reopens due' => [[[100, false], [100, true]], 'due', 0],
            'partial refund' => [[[100, false], [25, true]], 'partial', 75],
        ];
    }

    #[DataProvider('payments')]
    public function test_payment_status_and_net_paid_amount(array $payments, string $expectedStatus, float $paid): void
    {
        foreach ($payments as [$amount, $isReturn]) {
            DB::table('transaction_payments')->insert(['transaction_id' => 1, 'amount' => $amount, 'is_return' => $isReturn]);
        }
        DB::table('transaction_payments')->insert(['transaction_id' => 2, 'amount' => 999]);
        $util = new TransactionUtil;
        $this->assertEqualsWithDelta($paid, (float) $util->getTotalPaid(1), 0.00001);
        $this->assertSame($expectedStatus, $util->updatePaymentStatus(1));
        $this->assertDatabaseHas('transactions', ['id' => 1, 'payment_status' => $expectedStatus]);
        $this->assertDatabaseHas('transactions', ['id' => 2, 'payment_status' => 'due']);
    }

    public function test_accounting_directions_for_sales_purchases_and_returns(): void
    {
        foreach (['sell' => 'credit', 'purchase' => 'debit', 'expense' => 'debit',
            'purchase_return' => 'credit', 'sell_return' => 'debit', 'expense_refund' => 'credit'] as $type => $direction) {
            $this->assertSame($direction, AccountTransaction::getAccountTransactionType($type));
        }
    }
}
