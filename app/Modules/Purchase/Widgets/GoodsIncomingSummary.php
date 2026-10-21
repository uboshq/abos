<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Widgets;

use App\Core\Security\FieldSecurity;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseOrder;
use Illuminate\Support\Carbon;

/**
 * ⭐ ফোনের "মাল আসছে" উইজেট (৪×২) — এই সপ্তাহে যে ক্রয়াদেশের মাল আসার কথা, আর যেগুলোর দেরি হয়ে গেছে (মালিকের উইজেট-নকশা
 * ১৫, ২০ অক্টোবর ২০২৬; দলের চুক্তি E:\ABOS\app-widgets-ভিত্তি.md; ফোনের অংশ `goods_incoming.dart`)।
 *
 * ⭐ ওয়েবের ক্রয়ের ড্যাশবোর্ডের "মাল আসেনি" তালিকার একই ছাঁকনি ([[PurchaseDashboard::goodsNotYetIn()]]): নিশ্চিত, এখনো বন্ধ
 * হয়নি ([[PurchaseOrder::scopeOpen()]]), আসার তারিখ ধরে আগে। এখানে কেবল যার তারিখ এই সপ্তাহের শেষ (শুক্রবার, দেশের ছুটির
 * দিন) পর্যন্ত — আগের তারিখগুলোই দেরি। তারিখ না লেখা আদেশ নয়: কবে আসবে কেউ বলেনি, তাই "এই সপ্তাহে" বলা যায় না।
 *
 * ⛔ অঙ্ক কেবল ক্রয়মূল্য দেখার চাবিধারীর — ওয়েবের ক্রয়াদেশের পাতার একই নিয়ম (`FieldSecurity::visible(Product,
 * 'purchase_price')`); চাবি না থাকলে `amount` ঘরটাই নেই। দেয়াল মডেলের ([[ScopedToUserBranch]])।
 * চাবি `purchase.order.view` — ওয়েবের ক্রয়াদেশের তালিকার চাবি।
 */
final class GoodsIncomingSummary implements WidgetSummary
{
    public const ROWS = 3;

    public function key(): string
    {
        return 'goods_incoming';
    }

    public function module(): string
    {
        return 'purchase';
    }

    public function permission(): string
    {
        return 'purchase.order.view';
    }

    /**
     * @return array{link: string, count: int, late: int, rows: list<array<string, mixed>>}
     */
    public function data(User $user): array
    {
        $today = Carbon::today();
        $weekEnd = $today->copy()->endOfWeek(Carbon::FRIDAY)->toDateString();
        $due = fn () => PurchaseOrder::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->whereNotNull('expected_on')
            ->whereDate('expected_on', '<=', $weekEnd);

        $showsPrice = FieldSecurity::visible(Product::class, 'purchase_price');
        $rows = [];
        $orders = $due()->with(['supplier.branch', 'lines.product.unit'])
            ->orderBy('expected_on')->orderBy('id')
            ->limit(self::ROWS)->get();

        foreach ($orders as $order) {
            $rows[] = [
                'supplier' => $order->supplier?->name() ?? '—',
                'supplier_branch' => $order->supplier?->branch?->name(),
                'no' => (string) $order->document_no,
                'expected' => $order->expected_on->toDateString(),
                'late' => $order->expected_on->lt($today),
                ...self::goods($order),
                ...($showsPrice ? ['amount' => Money::round($order->total)] : []),
                'link' => '/purchase/orders/'.$order->id,
            ];
        }

        return [
            'link' => '/purchase/orders',
            'count' => $due()->count(),
            'late' => $due()->whereDate('expected_on', '<', $today->toDateString())->count(),
            'rows' => $rows,
        ];
    }

    /**
     * আসা বাকি পরিমাণ — "৪০০ বস্তা আটা"। ⓘ এক একক হলে যোগ আর একক; এক পণ্য হলে তার নামও। একক মিশ্র হলে যোগ অর্থহীন
     * (কার্টন + বস্তা), তখন কেবল কয়টা পণ্য।
     *
     * @return array{qty: ?string, unit: ?string, product: ?string, products: int}
     */
    private static function goods(PurchaseOrder $order): array
    {
        $pending = $order->lines->filter(fn ($line) => bccomp($line->pendingQty(), '0', 4) > 0);
        $units = $pending->map(fn ($line) => $line->product?->unit_id)->unique();
        $products = $pending->map(fn ($line) => $line->product_id)->unique();
        $sameUnit = $units->count() === 1;
        $unit = $sameUnit ? $pending->first()?->product?->unit : null;

        return [
            'qty' => $sameUnit
                ? Money::quantity($pending->reduce(fn (string $sum, $line) => bcadd($sum, $line->pendingQty(), 4), '0'))
                : null,
            'unit' => $unit === null ? null : (app()->getLocale() === 'bn' && filled($unit->name_bn) ? $unit->name_bn : $unit->name_en),
            'product' => $products->count() === 1 ? $pending->first()?->product?->name() : null,
            'products' => $products->count(),
        ];
    }
}
