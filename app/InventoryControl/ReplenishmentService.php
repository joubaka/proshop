<?php
namespace App\InventoryControl;

use App\InventoryPolicy;
use Illuminate\Support\Facades\DB;

class ReplenishmentService
{
    public function suggestedQuantity(float $dailySales, float $available, float $incoming, int $leadDays, int $safetyDays, float $alertQuantity, float $minimum, float $multiple): float
    {
        $target=max($alertQuantity,$dailySales*($leadDays+$safetyDays));
        $raw=max(0,$target-$available-$incoming);$multiple=max(0.0001,$multiple);
        return $raw>0?max($minimum,ceil($raw/$multiple)*$multiple):0;
    }
    public function fingerprint($rows): string
    {
        return hash('sha256', $rows->sortBy('variation_id')->map(fn($row) => implode('|', [
            $row->variation_id, number_format((float)$row->qty_available,4,'.',''),
            number_format((float)$row->open_po,4,'.',''), number_format((float)$row->daily_sales,6,'.',''),
            number_format((float)$row->suggested_order,4,'.',''), (int)($row->supplier_id ?? 0),
        ]))->implode(';'));
    }
    public function recommendations(int $businessId, int $locationId, int $days = 30)
    {
        $days = max(7, min(365, $days));
        $sales = DB::table('transaction_sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->where('t.business_id', $businessId)->where('t.location_id', $locationId)
            ->where('t.type', 'sell')->where('t.status', 'final')
            ->where('t.transaction_date', '>=', now()->subDays($days))
            ->groupBy('sl.variation_id')
            ->selectRaw('sl.variation_id, SUM(sl.quantity - sl.quantity_returned) sold')->pluck('sold', 'variation_id');

        $openPo = DB::table('purchase_lines as pl')->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->where('t.business_id', $businessId)->where('t.location_id', $locationId)
            ->where('t.type', 'purchase_order')->whereNotIn('t.status', ['completed','cancelled'])
            ->groupBy('pl.variation_id')->selectRaw('pl.variation_id, SUM(CASE WHEN pl.quantity > pl.po_quantity_purchased THEN pl.quantity - pl.po_quantity_purchased ELSE 0 END) open_qty')
            ->pluck('open_qty', 'variation_id');

        $policies = InventoryPolicy::where('business_id', $businessId)->where('location_id', $locationId)->get()->keyBy('variation_id');
        return DB::table('variation_location_details as vld')->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'vld.product_id')->where('p.business_id', $businessId)
            ->where('vld.location_id', $locationId)->where('p.enable_stock', 1)->where('p.is_inactive', 0)
            ->select('p.id as product_id','p.name','p.alert_quantity','v.id as variation_id','v.sub_sku','v.sell_price_inc_tax','v.default_purchase_price','vld.qty_available')
            ->orderBy('p.name')->get()->map(function ($row) use ($sales, $openPo, $policies, $days) {
                $policy = $policies->get($row->variation_id);
                $daily = max(0, (float) ($sales[$row->variation_id] ?? 0)) / $days;
                $lead = $policy?->lead_time_days ?? 7;
                $safetyDays = $policy?->safety_stock_days ?? 7;
                $target = max((float) ($row->alert_quantity ?? 0), $daily * ($lead + $safetyDays));
                $available = (float) $row->qty_available;
                $incoming = (float) ($openPo[$row->variation_id] ?? 0);
                $minimum = (float) ($policy?->minimum_order_quantity ?? 1);
                $multiple = max(0.0001, (float) ($policy?->order_multiple ?? 1));
                $suggested = $this->suggestedQuantity($daily,$available,$incoming,$lead,$safetyDays,(float)($row->alert_quantity??0),$minimum,$multiple);
                $row->daily_sales = $daily; $row->days_cover = $daily > 0 ? $available / $daily : null;
                $row->open_po = $incoming; $row->target_stock = $target; $row->suggested_order = $suggested;
                $row->supplier_id = $policy?->supplier_id; $row->lead_time_days = $lead; $row->safety_stock_days = $safetyDays;
                return $row;
            })->filter(fn ($row) => $row->suggested_order > 0)->values();
    }
}
