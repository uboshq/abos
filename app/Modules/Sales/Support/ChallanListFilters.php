<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\ListFilter\ChoiceFacet;
use App\Core\ListFilter\DateRangeFacet;
use App\Core\ListFilter\FilterSet;
use App\Core\ListFilter\ListCard;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Support\Places;
use App\Modules\Sales\Services\DeliveryStage;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ ডেলিভারি চালানের তালিকার ছাঁকনি — কেন্দ্রীয় ইঞ্জিনে তৃতীয় তালিকা (মালিক, ১৪ নভেম্বর ২০২৬; পরিকল্পনা §৫ "চালান")।
 *
 * ── ঠিকানার চাবি (পুরোনো লিংক অটুট) ─────────────────────────────────────────
 *   stage[]      অবস্থা — ধাপের পটির `?stage=draft` একক লিংকও চলে
 *   delivery[]   পৌঁছানো — awaiting (পৌঁছানোর অপেক্ষায়) · delivered (পৌঁছেছে)
 *   from / to    চালানের তারিখ · depot[] ডিপো · area[] এলাকা
 *   cancelled    বাতিলগুলোও — তালিকার নিজের কোয়েরি পড়ে
 *
 * ⓘ "পৌঁছেছে" মানে ডেলিভারির ধাপ [[DeliveryStage::DELIVERED]] লেখা — উপরের ট্যাবের ([[DeliveryOrderTabs]]) হুবহু শর্ত, তাই
 *   ট্যাব, কার্ড আর ছাঁকনি একই কথা বলে।
 */
final class ChallanListFilters
{
    public function set(): FilterSet
    {
        $stages = [];

        foreach (DocumentStatus::ALL as $status) {
            $stages[$status] = [
                'label' => __('core.status.'.$status),
                'tone' => match ($status) {
                    DocumentStatus::DRAFT => 'slate',
                    DocumentStatus::CONFIRMED => 'blue',
                    DocumentStatus::CLOSED => 'green',
                    default => 'red',
                },
                'apply' => fn (Builder $q) => $q->where('sal_challans.status', $status),
            ];
        }

        return new FilterSet([
            (new ChoiceFacet('stage', __('sales::filter.stage'), $stages))->look('blue', '●'),
            (new ChoiceFacet('delivery', __('sales::filter.delivery'), [
                'awaiting' => ['label' => __('sales::filter.delivery_awaiting'), 'tone' => 'amber', 'apply' => fn (Builder $q) => $this->awaiting($q)],
                'delivered' => ['label' => __('sales::filter.delivery_done'), 'tone' => 'green', 'apply' => fn (Builder $q) => $this->delivered($q)],
            ]))->look('amber', '🚚'),
            (new DateRangeFacet('date', __('sales::filter.challan_date'), function (Builder $q, ?string $from, ?string $to): void {
                if ($from !== null) {
                    $q->where('sal_challans.trx_date', '>=', $from);
                }
                if ($to !== null) {
                    $q->where('sal_challans.trx_date', '<=', $to);
                }
            }, fromKey: 'from', toKey: 'to'))->look('sky', '📅'),
            (new ChoiceFacet('depot', __('sales::filter.depot'), $this->depots()))->look('blue', '🏬'),
            $this->areaFacet()->look('teal', '◈'),
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
        $n = fn (int $count) => __('sales::filter.challans', ['n' => number_format($count)]);

        return [
            new ListCard(__('sales::filter.card_all_challans'), '📦', ['#2346d8', '#7c3aed'], [],
                fn (Builder $q) => $n($q->count()),
                fn (Builder $q) => __('sales::filter.card_all_challans_sub')),
            new ListCard(__('sales::filter.delivery_awaiting'), '⏳', ['#d97706', '#facc15'], ['delivery' => ['awaiting']],
                fn (Builder $q) => $n($this->awaiting($q)->count()),
                fn (Builder $q) => __('sales::filter.card_awaiting_sub')),
            new ListCard(__('sales::filter.delivery_done'), '✔', ['#059669', '#22c55e'], ['delivery' => ['delivered']],
                fn (Builder $q) => $n($this->delivered($q)->count()),
                fn (Builder $q) => __('sales::filter.card_delivered_sub')),
            new ListCard(__('core.status.draft'), '✎', ['#475569', '#94a3b8'], ['stage' => ['draft']],
                fn (Builder $q) => $n($q->where('sal_challans.status', DocumentStatus::DRAFT)->count()),
                fn (Builder $q) => __('sales::filter.card_draft_sub')),
        ];
    }

    private function delivered(Builder $query): Builder
    {
        return $query->where('sal_challans.status', '<>', DocumentStatus::CANCELLED)->whereExists($this->deliveredMark());
    }

    private function awaiting(Builder $query): Builder
    {
        return $query->whereIn('sal_challans.status', DocumentStatus::POSTED)->whereNotExists($this->deliveredMark());
    }

    private function deliveredMark(): Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('sal_delivery_states as ds')
            ->whereColumn('ds.delivery_challan_id', 'sal_challans.id')
            ->where('ds.stage', DeliveryStage::DELIVERED);
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
                'apply' => fn (Builder $q) => $q->where('sal_challans.branch_id', $branch->id),
            ];
        }

        return $options;
    }

    private function areaFacet(): ChoiceFacet
    {
        $section = Places::section(
            Location::TERRITORY,
            fn (array $ids) => fn (Builder $q) => $q->whereIn('sal_challans.customer_id',
                Customer::query()->whereIn('location_id', $ids ?: [0])->select('customers.id')),
            // ⓘ জোড়া নয়, ছোট উপ-প্রশ্ন — গ্রাহকের টেবিলেরও "status" আছে, জুড়লে তালিকার নিজের শর্ত দ্ব্যর্থ হত
            fn (Builder $q) => (clone $q)->reorder()->toBase()
                ->selectRaw('(SELECT place_customer.location_id FROM customers AS place_customer WHERE place_customer.id = sal_challans.customer_id) AS loc, COUNT(*) AS n')
                ->groupBy('loc')->get(),
        );

        return new ChoiceFacet('area', __('sales::filter.area'), $section['options'], countAll: $section['count']);
    }
}
