<?php

declare(strict_types=1);

namespace App\Modules\Customer\Support;

use App\Core\Contracts\CustomerSalesFilters;
use App\Core\ListFilter\ChoiceFacet;
use App\Core\ListFilter\DateRangeFacet;
use App\Core\ListFilter\FieldsFacet;
use App\Core\ListFilter\FilterSet;
use App\Core\ListFilter\RangeFacet;
use App\Core\Support\DateFormat;
use App\Core\Support\ViewedBranch;
use App\Models\LedgerEntry;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * গ্রাহকের তালিকার ছাঁকনি — মালিক, ১ অক্টোবর ২০২৬:
 * *"date range, due range, advance range, area wise, point wise, active/inactive"*, তারপর *"শুধু নিষ্ক্রিয়, শুধু সক্রিয়,
 * অমুক এরিয়া, ৫০–১০০ বাকি, অগ্রিম, ভালো কাস্টমার, টপ ৬ / টপ ১০, বটম ৫"*।
 *
 * ── ঠিকানার চাবি ───────────────────────────────────────────────────────
 *   created_from / created_to   গ্রাহক খোলার দিন
 *   due_min / due_max           সারির বকেয়া (ধনাত্মক), যেকোনো সীমা (৫০–১০০ চলে)
 *   advance_min / advance_max   অগ্রিম — ঋণাত্মক বকেয়া, ধনাত্মক অঙ্কে লেখা
 *   area / point                ঐ জায়গা আর তার নিচের পুরো ডাল (পয়েন্টের রুটসহ)
 *   status                      active (ডিফল্ট) · inactive · all; পুরনো `?inactive=1` = all
 *   quick                       due · advance · good · over_limit — এক ক্লিকের দৃশ্য
 *   rank / rank_n / rank_by     top|bottom, সংখ্যা (ডিফল্ট ১০), sales|due
 *   sales_from / sales_to       টপ/বটম বিক্রির সময় (ডিফল্ট গত ৯০ দিন)
 *
 * ── সিদ্ধান্ত (আমার, লেখা থাকল) ─────────────────────────────────────────
 *   বটম-বিক্রি কেবল যাঁরা ঐ সময়ে কিনেছেন তাঁদের মধ্যে (মালিকের কথা); বটম-বকেয়া কেবল যাঁদের বকেয়া আছে — নাহলে
 *   শূন্য-বকেয়ার সবাই "নিচের ৫" হতেন আর তালিকাটা অর্থহীন হত।
 *   ভালো কাস্টমার: গত ৯০ দিনে কিনেছেন, বকেয়া সীমার ভেতরে ([[Customer::scopeOverCreditLimit()]] নয়), আর কোনো
 *   অপরিশোধিত বিল নিজের বাকির দিন (খালি হলে ৩০) পেরোয়নি ([[CustomerSalesFilters]])।
 *
 * ⓘ সবকিছু SQL-এ, তালিকার কোয়েরির ভেতরে — পাতা, রপ্তানি আর ছাপা একই কোয়েরি চালায়, তাই তিনটাই একই সারি পায়।
 */
final class CustomerListFilters
{
    public const RANK_DEFAULT = 10;

    /** ⓘ অবস্থার ডিফল্ট — ছাঁকনিও এটা ধরে, টুলবারও (`defaults`): এই মানে থাকলে চালু ছাঁকনি নয়, প্যানেল খোলে না */
    public const DEFAULT_STATUS = 'active';

    public const SALES_DAYS = 90;

    public function __construct(private readonly CustomerSalesFilters $sales) {}

    /** ⓘ টপ/বটমের শিরোনাম — [[apply()]] চালালে ভরে */
    private ?string $rankTitle = null;

    /**
     * কোয়েরিতে ছাঁকনি বসায়; টপ/বটম চালু থাকলে তার শিরোনাম ফেরত দেয় (নাহলে null)।
     *
     * ⭐ ১৪ নভেম্বর ২০২৬ থেকে ছাঁকনি ইঞ্জিনের ঘোষণা ([[set()]], [[FilterSet]]) — প্যানেল, সংখ্যা আর চিপ সেখান থেকে।
     */
    public function apply(Builder $query, Request $request): ?string
    {
        $this->rankTitle = null;
        $this->set($request)->apply($query, $request);

        return $this->rankTitle;
    }

    /**
     * ⭐ গ্রাহক তালিকার ছাঁকনি — ঘোষণা (মালিক, ১ অক্টোবর আর ১৪ নভেম্বর ২০২৬)।
     *
     * ⓘ ঠিকানার চাবিগুলো আগের মতোই, তাই পুরোনো লিংক অটুট; এলাকা, পয়েন্ট আর দ্রুত দৃশ্যে এখন একাধিক টিক চলে (`area[]=…`)।
     */
    public function set(Request $request): FilterSet
    {
        return new FilterSet([
            new ChoiceFacet('status', __('customer::filter.status'), [
                'active' => ['label' => __('customer::filter.active'), 'apply' => fn (Builder $q) => $q->where('customers.is_active', true)],
                'inactive' => ['label' => __('customer::filter.inactive'), 'apply' => fn (Builder $q) => $q->where('customers.is_active', false)],
                'all' => ['label' => __('customer::filter.all'), 'apply' => fn (Builder $q) => $q],
            ], multi: false, default: $request->boolean('inactive') ? 'all' : self::DEFAULT_STATUS),
            new ChoiceFacet('quick', __('customer::filter.quick'), [
                'due' => ['label' => __('customer::filter.quick_due'), 'apply' => fn (Builder $q) => $q->where($this->outstanding(), '>', 0)],
                'advance' => ['label' => __('customer::filter.quick_advance'), 'apply' => fn (Builder $q) => $q->where($this->outstanding(), '<', 0)],
                'good' => ['label' => __('customer::filter.quick_good'), 'apply' => fn (Builder $q) => $this->good($q)],
                'over_limit' => ['label' => __('customer::filter.quick_over_limit'), 'apply' => fn (Builder $q) => $q->overCreditLimit()],
            ]),
            new ChoiceFacet('area', __('customer::filter.area'), $this->places(Location::TERRITORY)),
            new ChoiceFacet('point', __('customer::filter.point'), $this->places(Location::POINT)),
            new RangeFacet('due', __('customer::filter.due'), fn (Builder $q, ?string $min, ?string $max) => $this->between($q, $this->outstanding(), 1, $min, $max)),
            new RangeFacet('advance', __('customer::filter.advance'), fn (Builder $q, ?string $min, ?string $max) => $this->between($q, $this->outstanding(), -1, $min, $max)),
            new DateRangeFacet('created', __('customer::filter.created'), function (Builder $q, ?string $from, ?string $to): void {
                if ($from !== null) {
                    $q->where('customers.created_at', '>=', $from.' 00:00:00');
                }
                if ($to !== null) {
                    $q->where('customers.created_at', '<=', $to.' 23:59:59');
                }
            }),
            new FieldsFacet('rank', __('customer::filter.rank'), [
                ['key' => 'rank', 'label' => __('customer::filter.rank'), 'type' => 'select',
                    'options' => ['' => __('customer::filter.any'), 'top' => __('customer::filter.top'), 'bottom' => __('customer::filter.bottom')]],
                ['key' => 'rank_n', 'label' => __('customer::filter.rank_n'), 'type' => 'number', 'min' => 1, 'max' => 500, 'placeholder' => (string) self::RANK_DEFAULT],
                ['key' => 'rank_by', 'label' => __('customer::filter.rank_by'), 'type' => 'select',
                    'options' => ['sales' => __('customer::filter.by_sales'), 'due' => __('customer::filter.by_due')]],
                ['key' => 'sales_from', 'label' => __('customer::filter.sales_from'), 'type' => 'date'],
                ['key' => 'sales_to', 'label' => __('customer::filter.sales_to'), 'type' => 'date'],
            ], function (Builder $q) use ($request): void {
                $this->rankTitle = $this->rank($q, $request);
            }, fn (array $v) => (string) $this->rankTitleFor($request)),
        ]);
    }

    /**
     * এলাকা বা পয়েন্টের ঘর — দেখার শাখার, চালু, নামের ক্রমে; প্রতিটা ঘর ঐ জায়গা আর তার নিচের পুরো ডাল।
     *
     * @return array<string, array{label: string, apply: callable(Builder): mixed}>
     */
    private function places(string $level): array
    {
        $options = [];

        foreach (Location::query()->inViewedBranch()->atLevel($level)->where('is_active', true)->orderBy('name_en')->get() as $place) {
            $options[(string) $place->id] = [
                'label' => $place->name(),
                'apply' => fn (Builder $q) => $q->whereIn('customers.location_id', $place->selfAndDescendants()->pluck('id')->all() ?: [0]),
            ];
        }

        return $options;
    }

    /** হেডারে বাছা শাখার বকেয়া — [[Customer::scopeWithOutstandingInView()]]-এর হুবহু, সারিতে যা দেখায় তাই */
    private function outstanding(): Builder
    {
        return ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0)')
            ->whereColumn('ledger_entries.party_id', 'customers.id')
            ->where('ledger_entries.party_type', Customer::drillSourceType());
    }

    /**
     * সর্বনিম্ন–সর্বোচ্চ, যেকোনো একটা বা দুইটাই। ⓘ অগ্রিমে চিহ্ন উল্টো: খাতায় −৩,০০০ মানে ৩,০০০ অগ্রিম।
     */
    private function between(Builder $query, Builder $amount, int $sign, ?string $min, ?string $max): void
    {
        $query->where($amount, $sign > 0 ? '>' : '<', 0);

        if ($min !== null) {
            $query->where($amount, $sign > 0 ? '>=' : '<=', $sign > 0 ? $min : '-'.$min);
        }

        if ($max !== null) {
            $query->where($amount, $sign > 0 ? '<=' : '>=', $sign > 0 ? $max : '-'.$max);
        }
    }

    private function good(Builder $query): void
    {
        $this->sales->boughtBetween($query, now()->subDays(self::SALES_DAYS)->toDateString(), now()->toDateString());
        $query->whereNot(fn ($q) => $q->overCreditLimit());
        $this->sales->noOverdueBill($query);
    }

    private function rank(Builder $query, Request $request): ?string
    {
        $rank = (string) $request->query('rank');

        if (! in_array($rank, ['top', 'bottom'], true)) {
            return null;
        }

        $n = max(1, min(500, $request->integer('rank_n') ?: self::RANK_DEFAULT));
        $by = $request->query('rank_by') === 'due' ? 'due' : 'sales';
        $from = $this->date($request->query('sales_from')) ?? now()->subDays(self::SALES_DAYS)->toDateString();
        $to = $this->date($request->query('sales_to')) ?? now()->toDateString();

        $metric = $by === 'sales' ? $this->sales->salesTotal($from, $to) : $this->outstanding();

        if ($metric === null) {
            $query->whereRaw('1 = 0');

            return $this->rankTitle($rank, $n, $by, $from, $to, $request);
        }

        if ($by === 'sales') {
            $this->sales->boughtBetween($query, $from, $to);
        } elseif ($rank === 'bottom') {
            $query->where($this->outstanding(), '>', 0);
        }

        $direction = $rank === 'top' ? 'desc' : 'asc';

        // ⓘ প্রথম Nজনের আইডি আগে, তারপর তালিকা তাদের মধ্যেই — পাতা আর রপ্তানি দুটোই ঠিক Nজন পায়
        $ids = (clone $query)->reorder()->orderBy($metric, $direction)->orderBy('customers.id')
            ->limit($n)->pluck('customers.id')->all();

        $query->whereIn('customers.id', $ids ?: [0])->reorder()->orderBy($metric, $direction)->orderBy('customers.id');

        return $this->rankTitle($rank, $n, $by, $from, $to, $request);
    }

    /** ⓘ চিপের লেখা — টপ/বটমের শিরোনামই */
    private function rankTitleFor(Request $request): ?string
    {
        $rank = (string) $request->query('rank');
        $n = max(1, min(500, $request->integer('rank_n') ?: self::RANK_DEFAULT));
        $by = $request->query('rank_by') === 'due' ? 'due' : 'sales';
        $from = $this->date($request->query('sales_from')) ?? now()->subDays(self::SALES_DAYS)->toDateString();
        $to = $this->date($request->query('sales_to')) ?? now()->toDateString();

        return $this->rankTitle($rank, $n, $by, $from, $to, $request);
    }

    private function rankTitle(string $rank, int $n, string $by, string $from, string $to, Request $request): string
    {
        $period = $request->filled('sales_from') || $request->filled('sales_to')
            ? DateFormat::format($from).' – '.DateFormat::format($to)
            : __('customer::filter.last_days', ['days' => self::SALES_DAYS]);

        return __('customer::filter.rank_title', [
            'rank' => __('customer::filter.'.$rank),
            'n' => $n,
            'by' => __('customer::filter.by_'.$by),
            'period' => $by === 'sales' ? $period : __('customer::filter.today'),
        ]);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
