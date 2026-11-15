<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\ListFilter\ChoiceFacet;
use App\Core\ListFilter\DateRangeFacet;
use App\Core\ListFilter\FilterSet;
use App\Core\ListFilter\ListCard;
use App\Core\ListFilter\RangeFacet;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Support\Places;
use App\Modules\Sales\Services\OrderTracking;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ বিক্রয় আদেশের তালিকার ছাঁকনি — কেন্দ্রীয় ইঞ্জিনে চতুর্থ তালিকা (মালিক, ১৪ নভেম্বর ২০২৬: "এইভাবে এই কাজটা ধরে ফেলো";
 * পরিকল্পনা §৫ "অর্ডার")।
 *
 * ── ঠিকানার চাবি (পুরোনো লিংক অটুট) ─────────────────────────────────────────
 *   tab          উপরের ট্যাব — আগের মতোই, ছাঁকনির বাইরে ([[OrderTracking::applyListTab()]])
 *   stage[]      অবস্থা — খসড়া, অনুমোদনের অপেক্ষায়, সীমায় আটকে, নিশ্চিত, বন্ধ, ফেরত, বাতিল
 *   delivery[]   মাল যাওয়া — pending (একটুও যায়নি) · partial (কিছু গেছে) · back (ব্যাক অর্ডার)
 *   from / to    আদেশের তারিখ — আগের তারিখের ঘরের একই চাবি
 *   depot[] ডিপো · area[] এলাকা · total_min / total_max আদেশের টাকা
 *   cancelled    বাতিলগুলোও — তালিকার নিজের কোয়েরি পড়ে ("সব" ট্যাবে)
 *
 * ⛔ মাল যাওয়ার তিন ঘর উপরের ট্যাবের হুবহু শর্ত ([[OrderTracking::applyListTab()]]) — ট্যাব, কার্ড আর ছাঁকনি একই কথা বলে।
 */
final class OrderListFilters
{
    public function __construct(private readonly OrderTracking $tracking) {}

    public function set(): FilterSet
    {
        $stages = [];

        foreach (SalesOrderStatus::ALL as $status) {
            $stages[$status] = [
                'label' => __('sales::order_status.state.'.$status),
                'tone' => match ($status) {
                    SalesOrderStatus::DRAFT => 'slate',
                    SalesOrderStatus::AWAITING_APPROVAL, SalesOrderStatus::SUBMITTED => 'amber',
                    SalesOrderStatus::CREDIT_HELD => 'pink',
                    SalesOrderStatus::APPROVED, SalesOrderStatus::CONFIRMED => 'blue',
                    SalesOrderStatus::CLOSED => 'green',
                    default => 'red',
                },
                'apply' => fn (Builder $q) => $q->where('sal_orders.status', $status),
            ];
        }

        return new FilterSet([
            (new ChoiceFacet('stage', __('sales::filter.stage'), $stages))->look('blue', '●'),
            (new ChoiceFacet('delivery', __('sales::filter.order_delivery'), [
                'pending' => ['label' => __('sales::order_tabs.pending'), 'tone' => 'amber', 'apply' => fn (Builder $q) => $this->tab($q, OrderTracking::LIST_PENDING)],
                'partial' => ['label' => __('sales::order_tabs.partial'), 'tone' => 'blue', 'apply' => fn (Builder $q) => $this->tab($q, OrderTracking::LIST_PARTIAL)],
                'back' => ['label' => __('sales::order_tabs.back'), 'tone' => 'red', 'apply' => fn (Builder $q) => $this->tab($q, OrderTracking::LIST_BACK)],
            ]))->look('amber', '🚚'),
            (new DateRangeFacet('date', __('sales::filter.order_date'), function (Builder $q, ?string $from, ?string $to): void {
                if ($from !== null) {
                    $q->where('sal_orders.trx_date', '>=', $from);
                }
                if ($to !== null) {
                    $q->where('sal_orders.trx_date', '<=', $to);
                }
            }, fromKey: 'from', toKey: 'to'))->look('sky', '📅'),
            (new ChoiceFacet('depot', __('sales::filter.depot'), $this->depots()))->look('blue', '🏬'),
            $this->areaFacet()->look('teal', '◈'),
            (new RangeFacet('total', __('sales::filter.order_total'), function (Builder $q, ?string $min, ?string $max): void {
                if ($min !== null) {
                    $q->where('sal_orders.total', '>=', $min);
                }
                if ($max !== null) {
                    $q->where('sal_orders.total', '<=', $max);
                }
            }))->look('amber', '⇕'),
            // ⓘ বাতিলগুলোও — তালিকার নিজের কোয়েরি ("সব" ট্যাব) `?cancelled=1` পড়ে; এখানে কেবল ঘর আর চিপ
            (new ChoiceFacet('cancelled', __('sales::filter.cancelled'), [
                '1' => ['label' => __('sales::action.show_cancelled'), 'chip' => __('core.toolbar.filter_names.cancelled'), 'apply' => fn (Builder $q) => $q],
            ], counted: false))->look('slate', '✕'),
        ], $this->cards());
    }

    /**
     * @return list<ListCard>
     */
    private function cards(): array
    {
        $n = fn (int $count) => __('sales::filter.orders', ['n' => number_format($count)]);
        $money = fn (Builder $q) => '৳'.Money::format((string) $q->sum('sal_orders.total'));

        return [
            new ListCard(__('sales::order_status.state.awaiting_approval'), '✍', ['#d97706', '#facc15'], ['stage' => [SalesOrderStatus::AWAITING_APPROVAL]],
                fn (Builder $q) => $n((clone $q)->where('sal_orders.status', SalesOrderStatus::AWAITING_APPROVAL)->count()),
                fn (Builder $q) => $money($q->where('sal_orders.status', SalesOrderStatus::AWAITING_APPROVAL))),
            new ListCard(__('sales::order_tabs.pending'), '⏳', ['#2346d8', '#7c3aed'], ['delivery' => ['pending']],
                fn (Builder $q) => $n($this->tab(clone $q, OrderTracking::LIST_PENDING)->count()),
                fn (Builder $q) => $money($this->tab($q, OrderTracking::LIST_PENDING))),
            new ListCard(__('sales::order_tabs.back'), '↺', ['#dc2626', '#f97316'], ['delivery' => ['back']],
                fn (Builder $q) => $n($this->tab(clone $q, OrderTracking::LIST_BACK)->count()),
                fn (Builder $q) => __('sales::filter.card_back_sub')),
            new ListCard(__('sales::order_status.state.draft'), '✎', ['#475569', '#94a3b8'], ['stage' => [SalesOrderStatus::DRAFT]],
                fn (Builder $q) => $n($q->where('sal_orders.status', SalesOrderStatus::DRAFT)->count()),
                fn (Builder $q) => __('sales::filter.card_order_draft_sub')),
        ];
    }

    /** ⓘ উপরের ট্যাবের হুবহু শর্ত — নিজে নতুন করে লেখা নয় */
    private function tab(Builder $query, string $tab): Builder
    {
        return $this->tracking->applyListTab($query, $tab);
    }

    /**
     * @return array<string, array{label: string, apply: Closure(Builder): mixed}>
     */
    private function depots(): array
    {
        $options = [];

        foreach (Branch::query()->orderBy('name_en')->get() as $branch) {
            $options[(string) $branch->id] = [
                'label' => $branch->name(),
                'apply' => fn (Builder $q) => $q->where('sal_orders.branch_id', $branch->id),
            ];
        }

        return $options;
    }

    private function areaFacet(): ChoiceFacet
    {
        $section = Places::section(
            Location::TERRITORY,
            fn (array $ids) => fn (Builder $q) => $q->whereIn('sal_orders.customer_id',
                Customer::query()->whereIn('location_id', $ids ?: [0])->select('customers.id')),
            // ⓘ জোড়া নয়, ছোট উপ-প্রশ্ন — গ্রাহকের টেবিলেরও "status" আছে, জুড়লে তালিকার নিজের শর্ত দ্ব্যর্থ হত
            fn (Builder $q) => (clone $q)->reorder()->toBase()
                ->selectRaw('(SELECT place_customer.location_id FROM customers AS place_customer WHERE place_customer.id = sal_orders.customer_id) AS loc, COUNT(*) AS n')
                ->groupBy('loc')->get(),
        );

        return new ChoiceFacet('area', __('sales::filter.area'), $section['options'], countAll: $section['count']);
    }
}
