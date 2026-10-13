<?php

declare(strict_types=1);

namespace App\Modules\Customer\Support;

use App\Core\Contracts\CustomerSalesFilters;
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

    public const SALES_DAYS = 90;

    public function __construct(private readonly CustomerSalesFilters $sales) {}

    /**
     * কোয়েরিতে ছাঁকনি বসায়; টপ/বটম চালু থাকলে তার শিরোনাম ফেরত দেয় (নাহলে null)।
     */
    public function apply(Builder $query, Request $request): ?string
    {
        $this->status($query, $request);

        foreach (['created_from' => '>=', 'created_to' => '<='] as $key => $op) {
            if (($day = $this->date($request->query($key))) !== null) {
                $query->whereDate('customers.created_at', $op, $day);
            }
        }

        $this->range($query, $request, 'due', $this->outstanding(), 1);
        $this->range($query, $request, 'advance', $this->outstanding(), -1);

        foreach (['area', 'point'] as $key) {
            if ($request->integer($key) > 0) {
                $place = Location::query()->find($request->integer($key));
                $query->whereIn('customers.location_id', $place?->selfAndDescendants()->pluck('id')->all() ?? [0]);
            }
        }

        match ((string) $request->query('quick')) {
            'due' => $query->where($this->outstanding(), '>', 0),
            'advance' => $query->where($this->outstanding(), '<', 0),
            'over_limit' => $query->overCreditLimit(),
            'good' => $this->good($query),
            default => null,
        };

        return $this->rank($query, $request);
    }

    /** হেডারে বাছা শাখার বকেয়া — [[Customer::scopeWithOutstandingInView()]]-এর হুবহু, সারিতে যা দেখায় তাই */
    private function outstanding(): Builder
    {
        return ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0)')
            ->whereColumn('ledger_entries.party_id', 'customers.id')
            ->where('ledger_entries.party_type', Customer::drillSourceType());
    }

    private function status(Builder $query, Request $request): void
    {
        $status = $request->boolean('inactive') ? 'all' : (string) $request->query('status', 'active');

        match ($status) {
            'inactive' => $query->where('customers.is_active', false),
            'all' => null,
            default => $query->where('customers.is_active', true),
        };
    }

    /**
     * সর্বনিম্ন–সর্বোচ্চ, যেকোনো একটা বা দুইটাই। ⓘ অগ্রিমে চিহ্ন উল্টো: খাতায় −৩,০০০ মানে ৩,০০০ অগ্রিম।
     */
    private function range(Builder $query, Request $request, string $key, Builder $amount, int $sign): void
    {
        $min = $this->money($request->query($key.'_min'));
        $max = $this->money($request->query($key.'_max'));

        if ($min === null && $max === null) {
            return;
        }

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

    private function money(mixed $value): ?string
    {
        $value = is_string($value) ? trim(str_replace(',', '', $value)) : '';

        // ⛔ কেবল সাধারণ দশমিক — "1e5" `is_numeric` পেরিয়ে bcmath-এ ভেঙে ৫০০ দিত (পুনঃঅডিট ৯ অক্টোবর ২০২৬, গ্রাহক ১৭)
        return preg_match('/^\d{1,14}(\.\d{1,4})?$/', $value) === 1 ? bcadd($value, '0', 4) : null;
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
