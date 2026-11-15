<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\ListFilter\ChoiceFacet;
use App\Core\ListFilter\DateRangeFacet;
use App\Core\ListFilter\FilterSet;
use App\Core\ListFilter\ListCard;
use App\Core\ListFilter\Places;
use App\Core\ListFilter\RangeFacet;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\SalesInvoice;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিক্রয় বিলের তালিকার ছাঁকনি — কেন্দ্রীয় ইঞ্জিনে দ্বিতীয় তালিকা (মালিক, ১৪ নভেম্বর ২০২৬: "এইভাবে এই কাজটা ধরে ফেলো";
 *   পরিকল্পনা: মালিকের-ফাইল/কেন্দ্রীয়-ছাঁকনি-ও-রঙিন-তালিকা-পরিকল্পনা-১৪-নভেম্বর.html §৫ "ইনভয়েস / বিল")।
 *
 * ── ঠিকানার চাবি (পুরোনো লিংক অটুট) ─────────────────────────────────────────
 *   stage[]      অবস্থা — ধাপের পটির `?stage=draft` একক লিংকও চলে
 *   pay[]        আদায়ের অবস্থা — due (বাকি আছে) · overdue (মেয়াদ পার) · paid (পরিশোধিত)
 *   from / to    বিলের তারিখ — হোমের "আজকের বিক্রয়" ঠিক এখানেই নামে
 *   depot[]      ডিপো (শাখা) · area[] এলাকা (গ্রাহকের জায়গা আর তার নিচের ডাল)
 *   total_min / total_max   বিলের টাকা
 *   cancelled    বাতিলগুলোও — তালিকার নিজের কোয়েরি ([[SalesInvoiceController::listed()]]) এটা পড়ে; ফোনের তালিকাও একই দরজা
 *
 * ⛔ বাকির হিসাব [[SalesInvoice::dueSql()]]-এর হুবহু — বকেয়ার রিপোর্ট ([[CollectionDueReport]]) যা বলে তালিকাও তাই বলে;
 *   আদায়ের কাগজ, রসিদ ভাউচার, ফেরত, দাবি, ক্রেডিট নোট সব মিলে। কেবল খাতায় বসা বিল (খসড়া বা বাতিল নয়)।
 */
final class InvoiceListFilters
{
    public function set(Request $request): FilterSet
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
                'apply' => fn (Builder $q) => $q->where('sal_invoices.status', $status),
            ];
        }

        return new FilterSet([
            (new ChoiceFacet('stage', __('sales::filter.stage'), $stages))->look('blue', '●'),
            (new ChoiceFacet('pay', __('sales::filter.pay'), [
                'due' => ['label' => __('sales::filter.pay_due'), 'tone' => 'red', 'apply' => fn (Builder $q) => $this->owing($q)],
                'overdue' => ['label' => __('sales::filter.pay_overdue'), 'tone' => 'red', 'apply' => fn (Builder $q) => $this->owing($q, overdue: true)],
                'paid' => ['label' => __('sales::filter.pay_paid'), 'tone' => 'green', 'apply' => fn (Builder $q) => $this->paid($q)],
            ]))->look('red', '৳'),
            (new DateRangeFacet('date', __('sales::filter.date'), function (Builder $q, ?string $from, ?string $to): void {
                if ($from !== null) {
                    $q->where('sal_invoices.trx_date', '>=', $from);
                }
                if ($to !== null) {
                    $q->where('sal_invoices.trx_date', '<=', $to);
                }
            }, fromKey: 'from', toKey: 'to'))->look('sky', '📅'),
            (new ChoiceFacet('depot', __('sales::filter.depot'), $this->depots()))->look('blue', '🏬'),
            $this->areaFacet()->look('teal', '◈'),
            (new RangeFacet('total', __('sales::filter.total'), function (Builder $q, ?string $min, ?string $max): void {
                if ($min !== null) {
                    $q->where('sal_invoices.total', '>=', $min);
                }
                if ($max !== null) {
                    $q->where('sal_invoices.total', '<=', $max);
                }
            }))->look('amber', '⇕'),
            // ⓘ বাতিলগুলোও — তালিকার নিজের কোয়েরি ([[SalesInvoiceController::listed()]]) `?cancelled=1` পড়ে; এখানে কেবল ঘর আর চিপ
            (new ChoiceFacet('cancelled', __('sales::filter.cancelled'), [
                '1' => ['label' => __('sales::action.show_cancelled'), 'chip' => __('core.toolbar.filter_names.cancelled'), 'apply' => fn (Builder $q) => $q],
            ], counted: false))->look('slate', '✕'),
        ], $this->cards());
    }

    /**
     * ⓘ ধাপের পটির জন্য — ঠিক একটা অবস্থা বাছা থাকলে সেটা, নাহলে খালি
     */
    public static function singleStage(Request $request): string
    {
        $raw = $request->query('stage');
        $picked = is_array($raw) ? array_values($raw) : (is_scalar($raw) && (string) $raw !== '' ? [(string) $raw] : []);

        return count($picked) === 1 && in_array((string) $picked[0], DocumentStatus::ALL, true) ? (string) $picked[0] : '';
    }

    /**
     * @return list<ListCard>
     */
    private function cards(): array
    {
        $bills = fn (int $n) => __('sales::filter.bills', ['n' => number_format($n)]);

        return [
            new ListCard(__('sales::filter.card_all'), '🧾', ['#2346d8', '#7c3aed'], [],
                fn (Builder $q) => $bills($q->count()),
                fn (Builder $q) => '৳'.Money::format((string) $q->sum('sal_invoices.total'))),
            new ListCard(__('sales::filter.pay_due'), '⏳', ['#dc2626', '#f97316'], ['pay' => ['due']],
                fn (Builder $q) => $bills($this->owing($q)->count()),
                fn (Builder $q) => '৳'.Money::format($this->dueSum($q))),
            new ListCard(__('sales::filter.pay_overdue'), '⏱', ['#9f1239', '#7c3aed'], ['pay' => ['overdue']],
                fn (Builder $q) => $bills($this->owing($q, overdue: true)->count()),
                fn (Builder $q) => '৳'.Money::format($this->dueSum($q, overdue: true))),
            new ListCard(__('core.status.draft'), '✎', ['#475569', '#94a3b8'], ['stage' => ['draft']],
                fn (Builder $q) => $bills($q->where('sal_invoices.status', DocumentStatus::DRAFT)->count()),
                fn (Builder $q) => __('sales::filter.card_draft_sub')),
        ];
    }

    /** কেবল খাতায় বসা বিল, যার বাকি আছে — বকেয়ার রিপোর্টের একই শর্ত */
    private function owing(Builder $query, bool $overdue = false): Builder
    {
        return $query->whereIn('sal_invoices.id', $this->dueIds($overdue, fn (QueryBuilder $i) => $i->whereRaw(SalesInvoice::dueSql('i').' > 0.0001')));
    }

    private function paid(Builder $query): Builder
    {
        return $query->whereIn('sal_invoices.id', $this->dueIds(false, fn (QueryBuilder $i) => $i->whereRaw(SalesInvoice::dueSql('i').' <= 0.0001')));
    }

    /**
     * @param  Closure(QueryBuilder): mixed  $when
     */
    private function dueIds(bool $overdue, Closure $when): QueryBuilder
    {
        $ids = DB::query()->fromSub(SalesInvoice::query()->posted()->withCollected()->toBase(), 'i')->select('i.id');
        $when($ids);

        if ($overdue) {
            $ids->whereRaw('COALESCE(i.due_on, i.trx_date) < ?', [Carbon::today()->toDateString()]);
        }

        return $ids;
    }

    /** ⓘ বাকির যোগ — তালিকার নিজের কোয়েরির বিলগুলোর মধ্যে */
    private function dueSum(Builder $query, bool $overdue = false): string
    {
        $mine = (clone $query)->toBase()->select('sal_invoices.id');
        $sum = DB::query()->fromSub(SalesInvoice::query()->posted()->withCollected()->toBase(), 'i')
            ->whereIn('i.id', $mine)
            ->whereRaw(SalesInvoice::dueSql('i').' > 0.0001')
            ->when($overdue, fn ($i) => $i->whereRaw('COALESCE(i.due_on, i.trx_date) < ?', [Carbon::today()->toDateString()]))
            ->sum(DB::raw(SalesInvoice::dueSql('i')));

        return bcadd((string) $sum, '0', 4);
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
                'apply' => fn (Builder $q) => $q->where('sal_invoices.branch_id', $branch->id),
            ];
        }

        return $options;
    }

    /**
     * এলাকা — গ্রাহকের জায়গা আর তার নিচের ডাল; গাছ একবারই পড়া, সংখ্যা এক কোয়েরিতে ([[Places]])।
     */
    private function areaFacet(): ChoiceFacet
    {
        $section = Places::section(
            Location::TERRITORY,
            fn (array $ids) => fn (Builder $q) => $q->whereIn('sal_invoices.customer_id',
                Customer::query()->whereIn('location_id', $ids ?: [0])->select('customers.id')),
            // ⓘ জোড়া নয়, ছোট উপ-প্রশ্ন — গ্রাহকের টেবিলেরও "status" আছে, জুড়লে তালিকার নিজের শর্ত দ্ব্যর্থ হত
            fn (Builder $q) => (clone $q)->reorder()->toBase()
                ->selectRaw('(SELECT place_customer.location_id FROM customers AS place_customer WHERE place_customer.id = sal_invoices.customer_id) AS loc, COUNT(*) AS n')
                ->groupBy('loc')->get(),
        );

        return new ChoiceFacet('area', __('sales::filter.area'), $section['options'], countAll: $section['count']);
    }
}
