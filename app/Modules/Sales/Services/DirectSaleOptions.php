<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeRatio;
use App\Modules\MasterData\Models\PaymentTerm;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ কাউন্টারের বাছাইয়ের তালিকা — লট, টাকার খাত, শর্ত; ওয়েবের কাউন্টার আর ফোনের কাউন্টার একই জায়গা থেকে পড়ে
 * (৪ অক্টোবর ২০২৬, মালিক: *"direct sales er counter banaw app e"*)।
 *
 * ⓘ দেহগুলো [[DirectSaleController]] থেকে হুবহু কেটে আনা — হাতে লেখা নয়। ⛔ দুই কাউন্টারে দুই তালিকা হলে একদিন
 * ফোন এমন লট দেখাত যা সেবা নিত না, বা অন্যের নগদ বাক্স।
 */
final class DirectSaleOptions
{
    /**
     * টাকার খাত — নগদ, ব্যাংক, মোবাইল মানি; অন্য শাখার টিল আর অন্যের নগদ বাক্স বাদ।
     *
     * @return list<array{id: string, label: string, parent: string}>
     */
    public function moneyAccounts(): array
    {
        return Account::query()->notAnotherBranchsTill()
            ->where('is_group', false)
            ->whereIn('parent_id', Account::query()
                ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
            ->orderBy('code')
            ->with('parent:id,code')
            ->get(['id', 'parent_id', 'code', 'name_en', 'name_bn', 'money_kind'])
                /*
                 * ⛔ অন্যের নগদ বাক্স তালিকায় নয় — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"ekjoner
                 * cash account e r ekjon taka nite parbe na r ta onno joner idte show korbe na"*।
                 * ⓘ রসিদ ভাউচারের একই নিয়ম ([[CashTill::mayUse()]], [[DepositFormOptions]]); পোস্টের
                 * সময় সার্ভারও আটকায় ([[VoucherService::assertCashLandsInOwnTill()]])।
                 */
            ->filter(fn (Account $a) => ! $a->isCash() || CashTill::mayUse(auth()->id(), (int) $a->id))
            ->map(fn (Account $a): array => [
                'id' => (string) $a->id,
                'label' => $a->code.' · '.$a->name(),
                /* কোন মায়ের সন্তান — ছাঁকনিটা এটাই দেখে।
                       ১১০১ নগদ · ১১০২ ব্যাংক · ১১০৫ মোবাইল মানি */
                'parent' => (string) ($a->parent?->code ?? ''),
            ])
            ->values()
            ->all();
    }

    public function lots(?Warehouse $warehouse): array
    {
        if ($warehouse === null) {
            return [];
        }

        $balances = DB::table('inv_stock_movements')
            ->selectRaw('batch_id, COALESCE(SUM(floor_change), 0) as qty')
            ->where('company_id', CompanyContext::id())
            ->where('warehouse_id', $warehouse->id)
            ->whereNotNull('batch_id')
            ->groupBy('batch_id')
            ->pluck('qty', 'batch_id');

        $batches = Batch::query()
            ->whereIn('product_id', Product::query()->active()->where('track_batch', true)->select('id'))
            ->unexpired()
            ->fefo()
            ->get();

        // ⭐ লটের ফ্রি অনুপাত — লটের ঘরেই দেখায় (মালিক, ৪ অক্টোবর ২০২৬); সব লট একটাই প্রশ্নে
        $came = app(FreeRatio::class)->arrivedInMany($batches->pluck('id')->all());

        return $batches
            ->map(fn (Batch $b) => [
                'id' => (string) $b->id,
                'productId' => (string) $b->product_id,
                'no' => (string) $b->batch_no,
                'expiry' => $b->expiry_date?->toDateString() ?? '',
                'qty' => (string) ($balances[$b->id] ?? '0'),
                'paid' => $came[(string) $b->id]['paid'] ?? '0',
                'free' => $came[(string) $b->id]['free'] ?? '0',
            ])
            /*
             * ⛔ যে লটে কিছু নেই সে বাছাইয়ের তালিকায় আসে না। ⓘ ওটা বেছে
             * ফেললে সারিটা কার্টে উঠত আর সংরক্ষণের সময় ভেঙে পড়ত —
             * অর্থাৎ ভুলটা ধরা পড়ত ত্রিশটা সারি তোলার পরে।
             */
            ->filter(fn (array $lot) => bccomp($lot['qty'], '0', 4) > 0)
            ->groupBy('productId')
            ->map(fn ($rows) => $rows->values()->all())
            ->all();
    }

    public function paymentTerms(): array
    {
        $terms = [
            ['value' => 'cash', 'label' => __('sales::field.term_cash')],

            /*
             * ⭐ COD এখানে সত্যিই আলাদা কিছু বোঝায়, আর ওটাই ক্রয়ের
             * সাথে পার্থক্য।
             *
             * নগদ  → টাকা ড্রয়ারে, জমার ঘরে বসে
             * COD  → মাল ভ্যানে গেল, টাকা ফিরবে ডেলিভারিম্যানের সাথে
             *
             * ⚠️ দুইটার **তারিখ একই দিন**, তবু একটা আদায় হয়ে গেছে আর
             * আরেকটা পাওনা — খাতায় দুইটা সম্পূর্ণ আলাদা অবস্থা।
             */
            ['value' => 'cod', 'label' => __('sales::field.term_cod')],
        ];

        $rows = PaymentTerm::query()
            ->where('is_active', true)
            ->orderBy('days')
            ->get(['id', 'code', 'name_en', 'name_bn', 'days']);

        foreach ($rows as $row) {
            if ((int) $row->days <= 0) {
                continue;
            }

            $terms[] = [
                'value' => 'credit:'.(int) $row->days,
                'label' => __('sales::field.term_credit', ['count' => (int) $row->days]),
            ];
        }

        $terms[] = ['value' => 'month_end', 'label' => __('sales::field.term_month_end')];
        $terms[] = ['value' => 'fixed', 'label' => __('sales::field.term_fixed')];

        return $terms;
    }
}
