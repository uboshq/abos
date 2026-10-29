<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Widgets;

use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Core\Widgets\WidgetSummary;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;

/**
 * ⭐ ফোনের "মজুদ সতর্কতা" উইজেট (৪×২) — পুনঃক্রয়ের সীমার কাছে নেমে আসা পণ্য (মালিকের উইজেট-নকশা ৫, ২০ অক্টোবর ২০২৬;
 * দলের চুক্তি E:\ABOS\app-widgets-ভিত্তি.md; ফোনের অংশ `stock_alerts.dart`)।
 *
 * ⛔ বিক্রয়কর্মীর ফোনে এই উইজেট আসে না: চাবি `inventory.stock.view` — ওয়েবের মজুদের পাতার একই চাবি, যা Field Sales আর
 * বিক্রয়কর্মীর রোলে নেই (মালিক, ১ অক্টোবর ২০২৬: SR-এর ফোনে মজুদ নয়)। দরজা ([[WidgetSummaryController]]) চাবি ছাড়া চাবিটাই
 * উত্তরে রাখে না, তাই ফোন উইজেটটা দেখায় না।
 *
 * ⭐ সংখ্যা ওয়েবের একই উৎস থেকে — [[StockFacts]]: গোনা [[StockFacts::belowReorder()]] (মজুদের ড্যাশবোর্ডের "পুনঃক্রয়ের
 * নিচে"-র একই সংখ্যা), সারি [[StockFacts::lowestShare()]] (একই সংজ্ঞা, সীমার কম ভাগ আগে)। শাখা আর গুদামের দেয়াল ওখানেই
 * ([[Warehouse::idsInViewedBranch()]], [[Product::scopeSoldInViewedBranch()]])।
 *
 * ⓘ কেবল পড়া। পরিমাণ লেখা হিসেবে (লাখের কমা, ভগ্নাংশ থাকলে তবেই), আর `share` = বিক্রয়যোগ্য ÷ সীমা, bcmath, ফোন দাগ আঁকে।
 */
final class StockAlertsSummary implements WidgetSummary
{
    /** নকশার ৪×২-এ তিনটা সারি; বাকিগুলো "আরও Nটা" */
    public const ROWS = 3;

    public function __construct(private readonly StockFacts $facts) {}

    public function key(): string
    {
        return 'stock_alerts';
    }

    public function module(): string
    {
        return 'inventory';
    }

    public function permission(): string
    {
        return 'inventory.stock.view';
    }

    /**
     * @return array{link: string, count: int, warehouse: ?string, branch: ?string, rows: list<array{product: string, available: string, reorder: string, share: string, unit: ?string, link: string}>}
     */
    public function data(User $user): array
    {
        $rows = [];
        foreach ($this->facts->lowestShare(self::ROWS) as $product) {
            $available = Money::of($product->available_qty ?? '0');
            $reorder = Money::of($product->reorder_level);
            $rows[] = [
                'product' => $product->name(),
                'available' => Money::quantity($available),
                'reorder' => Money::quantity($reorder),
                // ⓘ সীমা > ০ — belowReorderQuery-ই তা বাছে, তাই ভাগে শূন্য নেই
                'share' => bcdiv($available, $reorder, 4),
                'unit' => $product->unit === null ? null : self::named($product->unit->name_bn, $product->unit->name_en),
                'link' => '/inventory/stock?q='.rawurlencode((string) $product->code),
            ];
        }

        return [
            // ⓘ পুরো উইজেটে চাপলে — মজুদের পাতা, কম আগে (ওয়েবের ড্যাশবোর্ডের তালিকার একই লিংক)
            'link' => '/inventory/stock?sort=available',
            'count' => $this->facts->belowReorder(),
            ...$this->where($user),
            'rows' => $rows,
        ];
    }

    /**
     * মাথার "গুদাম: প্রধান · SL-Super Group" — দেখার সীমায় একটাই গুদাম হলে তার নাম আর শাখা; নইলে কেবল বাছা শাখা (বা কিছুই না
     * = সব গুদাম)।
     *
     * @return array{warehouse: ?string, branch: ?string}
     */
    private function where(User $user): array
    {
        $ids = Warehouse::idsInViewedBranch();
        if ($ids !== null && count($ids) === 1) {
            $warehouse = Warehouse::query()->with('branch')->find($ids[0]);
            if ($warehouse !== null) {
                return [
                    'warehouse' => self::named($warehouse->name_bn, $warehouse->name_en),
                    'branch' => $warehouse->branch?->name(),
                ];
            }
        }

        $branch = ViewedBranch::one($user);

        return ['warehouse' => null, 'branch' => $branch === null ? null : Branch::query()->find($branch)?->name()];
    }

    private static function named(?string $bn, ?string $en): string
    {
        return app()->getLocale() === 'bn' && filled($bn) ? (string) $bn : (string) $en;
    }
}
