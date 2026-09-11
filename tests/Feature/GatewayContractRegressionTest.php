<?php

namespace Tests\Feature;

use App\Support\GatewayAmount;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class GatewayContractRegressionTest extends \Tests\TestCase
{
    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(new \Stripe\HttpClient\CurlClient);
        \Stripe\Stripe::setApiKey(null);
        parent::tearDown();
    }

    public static function amounts(): array
    {
        return [['100.25', 'ZAR', 10025], ['100', 'JPY', 100], ['100', 'UGX', 10000], ['5', 'ISK', 500], ['0.29', 'USD', 29]];
    }

    #[DataProvider('amounts')]
    public function test_stripe_minor_units_match_currency($amount, $currency, $expected): void
    {
        $this->assertSame($expected, GatewayAmount::stripe($amount, $currency));
    }

    public function test_unsupported_fraction_is_not_silently_over_or_undercharged(): void
    {
        foreach ([['1.001', 'ZAR'], ['1.5', 'JPY'], ['1.5', 'UGX'], ['0', 'ZAR'], ['-5', 'USD']] as [$amount, $currency]) {
            try { GatewayAmount::stripe($amount, $currency); $this->fail('Expected invalid amount.'); }
            catch (\InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
    }

    public static function charges(): array
    {
        return ['confirmed' => [true, true, 10025, 'zar', true], 'unpaid' => [false, true, 10025, 'zar', false],
            'uncaptured' => [true, false, 10025, 'zar', false], 'wrong amount' => [true, true, 9999, 'zar', false],
            'wrong currency' => [true, true, 10025, 'usd', false]];
    }

    #[DataProvider('charges')]
    public function test_real_stripe_sdk_sends_minor_units_and_verifies_confirmation_without_network(bool $paid, bool $captured, int $returnedAmount, string $currency, bool $confirmed): void
    {
        $client = Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        $client->shouldReceive('request')->once()->andReturnUsing(function ($method, $url, $headers, $params) use ($paid, $captured, $returnedAmount, $currency) {
            $this->assertSame('post', $method);
            $this->assertStringEndsWith('/v1/charges', $url);
            $this->assertContains('Idempotency-Key: local-contract-attempt', $headers);
            $this->assertSame(10025, $params['amount']);
            $this->assertSame('zar', $params['currency']);
            return [json_encode(['id' => 'ch_local_contract', 'object' => 'charge', 'paid' => $paid, 'captured' => $captured,
                'amount' => $returnedAmount, 'currency' => $currency]), 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($client);
        $businessUtil = Mockery::mock(\App\Utils\BusinessUtil::class)->makePartial();
        $businessUtil->shouldReceive('getDetails')->with(1)->andReturn((object) ['currency_code' => 'ZAR']);
        $this->app->instance(\App\Utils\BusinessUtil::class, $businessUtil);
        $invoice = new \App\Transaction;
        $business = new \App\Business(['pos_settings' => json_encode(['stripe_secret_key' => 'sk_test_local_dummy'])]);
        $business->id = 1;
        $invoice->setRelation('business', $business);
        $request = Request::create('/', 'POST', ['stripeToken' => 'tok_local_dummy']);
        $request->attributes->set('invoice_payment_attempt_id', 'local-contract-attempt');
        if (!$confirmed) { $this->expectException(\RuntimeException::class); }
        $this->assertSame('ch_local_contract', app(\App\Http\Controllers\SellPosController::class)->pay_stripe($invoice, '100.25', $request));
    }
}
