<?php
namespace Tests\Unit;
use App\InventoryControl\ReplenishmentService;
use PHPUnit\Framework\TestCase;
class ReplenishmentServiceTest extends TestCase
{
    public function test_it_accounts_for_lead_time_safety_stock_on_hand_and_open_orders(): void
    {
        $service=new ReplenishmentService;
        $this->assertSame(5.0,$service->suggestedQuantity(2,10,5,5,5,0,1,5));
    }
    public function test_it_respects_alert_floor_minimum_and_order_multiple(): void
    {
        $service=new ReplenishmentService;
        $this->assertSame(12.0,$service->suggestedQuantity(0,1,0,7,7,10,12,6));
        $this->assertSame(0.0,$service->suggestedQuantity(1,20,10,5,5,0,1,1));
    }
}
