<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Models\LedgerEntry;
use App\Modules\Sales\Models\CustomerTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ডিলারের মাসিক আদায়ের লক্ষ্য — মালিক, ৩ অক্টোবর ২০২৬ (বিলের "টার্গেট রিমাইন্ডার")।
 *
 * ── অর্জন কী ─────────────────────────────────────────────────────────────
 * মাসের ১ তারিখ থেকে আজ পর্যন্ত ডিলারের খাতায় **আসা টাকা**: আদায়, রসিদ ভাউচার, পাশ হওয়া চেক ([[INFLOW]])।
 * ⛔ ফেরত মাল বা জাবেদা নয় — ওতে খাতা কমে, অথচ টাকা আসেনি। ⛔ চেক কেবল ক্লিয়ার হলে (২৬ সেপ্টেম্বর): পুরনো
 * পথে হাতে আসার দিনই খাতায় উঠে যাওয়া যে চেক এখনো পাশ হয়নি, তার টাকা বাদ ([[unclearedThisMonth()]])।
 * ⓘ কোম্পানির ছাঁকনি [[Customer::outstanding()]]-এর মতোই — খাতার নিজের স্কোপ।
 *
 * ── ব্যাংক-দিন ───────────────────────────────────────────────────────────
 * আজ থেকে শেষ তারিখ পর্যন্ত রবি–বৃহস্পতি; শুক্র আর শনি বাদ। ⚠️ ছুটির টেবিল এখনো নেই — হলে এখানেই বাদ পড়বে।
 */
final class CustomerTargetService
{
    /** যে উৎসে খাতায় টাকা আসে — আদায়, রসিদ ভাউচার, চেক পাশ */
    public const INFLOW = ['collection', 'receipt_voucher', 'cheque', 'cheque:cleared'];

    /** ফেরত চেক — হাতে আসার দিন যে টাকা গোনা হয়েছিল, তা আবার বাদ */
    public const BOUNCED = 'cheque:bounced';

    public function readMonth(?string $raw): Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return Carbon::today()->startOfMonth();
        }

        try {
            return Carbon::parse($raw)->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    /**
     * এক মাসের লক্ষ্যগুলো বসানো — ফাঁকা বা ০ মানে ঐ ডিলারের ঐ মাসের লক্ষ্য তুলে দেওয়া।
     *
     * @param  array<int|string, array{amount?: mixed, closes_on?: mixed}>  $rows  গ্রাহকের id → লক্ষ্য আর শেষ তারিখ
     */
    public function setForMonth(Carbon $month, array $rows): void
    {
        $month = $month->copy()->startOfMonth();

        if ($month->greaterThan(Carbon::today()->startOfMonth()->addYears(2))) {
            throw ValidationException::withMessages(['month' => __('sales::customer_target.month_too_far')]);
        }

        DB::transaction(function () use ($month, $rows): void {
            foreach ($rows as $customerId => $row) {
                $this->setOne((int) $customerId, $month, $row['amount'] ?? null, $row['closes_on'] ?? null);
            }
        });
    }

    /** একজন ডিলারের এক মাস — ইমপোর্ট আর পাতা দুইটাই এই পথে, একই যাচাই। */
    public function setOne(int $customerId, Carbon $month, mixed $amount, mixed $closesOn): void
    {
        $month = $month->copy()->startOfMonth();
        $amount = trim((string) ($amount ?? ''));

        if ($amount === '' || (is_numeric($amount) && bccomp($amount, '0', 4) === 0)) {
            CustomerTarget::query()->where('customer_id', $customerId)->whereDate('month', $month->toDateString())->delete();

            return;
        }

        if (! is_numeric($amount) || bccomp($amount, '0', 4) < 0) {
            throw ValidationException::withMessages(['amount' => __('sales::customer_target.amount_invalid')]);
        }

        $closes = blank($closesOn) ? $month->copy()->endOfMonth() : Carbon::parse((string) $closesOn);

        if (! $closes->isSameMonth($month)) {
            throw ValidationException::withMessages(['closes_on' => __('sales::customer_target.closes_in_month')]);
        }

        CustomerTarget::query()->updateOrCreate(
            ['company_id' => CompanyContext::id(), 'customer_id' => $customerId, 'month' => $month->toDateString()],
            ['amount' => bcadd($amount, '0', 4), 'closes_on' => $closes->toDateString(), 'created_by' => Auth::id() !== null && Auth::user() instanceof \App\Models\User ? Auth::id() : null],
        );
    }

    /**
     * বিলের বাক্সের অঙ্ক — লক্ষ্য না থাকলে `null`, আর তখন বাক্সটাই দেখায় না।
     *
     * @return array{month: Carbon, target: string, achieved: string, remaining: string, closes_on: Carbon, bank_days: int}|null
     */
    public function reminderFor(int $customerId, ?Carbon $on = null): ?array
    {
        $on = ($on ?? Carbon::today())->copy()->startOfDay();
        $month = $on->copy()->startOfMonth();

        $target = CustomerTarget::query()
            ->where('customer_id', $customerId)
            ->whereDate('month', $month->toDateString())
            ->first();

        if ($target === null) {
            return null;
        }

        // ⓘ পাতার একই হিসাব — বিল আর লক্ষ্যের পাতা কখনো দুই অঙ্ক বলবে না
        $achieved = $this->achievedFor([$customerId], $month, $on)[$customerId];
        $remaining = bcsub((string) $target->amount, $achieved, 4);

        return [
            'month' => $month,
            'target' => bcadd((string) $target->amount, '0', 4),
            'achieved' => $achieved,
            'remaining' => bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000',
            'closes_on' => $target->closes_on->copy(),
            'bank_days' => $this->bankDays($on, $target->closes_on->copy()),
        ];
    }

    /**
     * লক্ষ্যের পাতার সারি — প্রতি সচল ডিলার এক সারি, লক্ষ্য আর অর্জনসহ।
     *
     * ⓘ অর্জন সবার জন্য একবারে গোনা ([[achievedFor()]]) — চারশো ডিলারে চারশো কোয়েরি নয়।
     *
     * @return list<array{customer: \App\Modules\Customer\Models\Customer, target: ?string, closes_on: ?string, achieved: string}>
     */
    public function board(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $to = Carbon::today()->lt($month->copy()->endOfMonth()) ? Carbon::today() : $month->copy()->endOfMonth();

        $customers = \App\Modules\Customer\Models\Customer::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_en', 'name_bn']);
        $targets = CustomerTarget::query()->whereDate('month', $month->toDateString())->get()->keyBy('customer_id');
        $achieved = $to->lt($month) ? [] : $this->achievedFor($customers->pluck('id')->map(fn ($id) => (int) $id)->all(), $month, $to);

        return $customers->map(fn ($c) => [
            'customer' => $c,
            'target' => isset($targets[$c->id]) ? (string) $targets[$c->id]->amount : null,
            'closes_on' => isset($targets[$c->id]) ? $targets[$c->id]->closes_on->toDateString() : null,
            'achieved' => $achieved[(int) $c->id] ?? '0.0000',
        ])->values()->all();
    }

    /**
     * অনেক ডিলারের অর্জন একবারে — [[reminderFor()]]-এর একই নিয়ম, দল বেঁধে।
     *
     * @param  list<int>  $customerIds
     * @return array<int, string>
     */
    public function achievedFor(array $customerIds, Carbon $from, Carbon $to): array
    {
        if ($customerIds === []) {
            return [];
        }

        $range = [$from->toDateString(), $to->toDateString()];
        $base = fn () => LedgerEntry::query()->where('party_type', 'customer')->whereIn('party_id', $customerIds)->whereBetween('trx_date', $range)->groupBy('party_id');

        $in = $base()->whereIn('source_type', self::INFLOW)->selectRaw('party_id, SUM(credit) as v')->pluck('v', 'party_id');
        $out = $base()->where('source_type', self::BOUNCED)->selectRaw('party_id, SUM(debit) as v')->pluck('v', 'party_id');
        $held = DB::table('acc_cheques as ch')
            ->where('ch.company_id', CompanyContext::id())->whereNull('ch.deleted_at')
            ->where('ch.direction', 'received')->where('ch.party_type', 'customer')->whereIn('ch.party_id', $customerIds)
            ->whereIn('ch.status', ['pending', 'deposited'])->whereBetween('ch.received_on', $range)
            ->where(fn ($q) => $q->whereNotNull('ch.collection_id')->orWhereNotNull('ch.voucher_id')
                ->orWhereExists(fn ($e) => $e->from('ledger_entries as le')->where('le.source_type', 'cheque')->whereColumn('le.source_id', 'ch.id')))
            ->groupBy('ch.party_id')->selectRaw('ch.party_id, SUM(ch.amount) as v')->pluck('v', 'party_id');

        $out2 = [];

        foreach ($customerIds as $id) {
            $v = bcsub(bcsub((string) ($in[$id] ?? 0), (string) ($out[$id] ?? 0), 4), (string) ($held[$id] ?? 0), 4);
            $out2[$id] = bccomp($v, '0', 4) > 0 ? $v : '0.0000';
        }

        return $out2;
    }

    /** আজ থেকে শেষ তারিখ পর্যন্ত ব্যাংক খোলার দিন — রবি থেকে বৃহস্পতি। */
    public function bankDays(Carbon $from, Carbon $to): int
    {
        $days = 0;

        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            if (! in_array($d->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true)) {
                $days++;
            }
        }

        return $days;
    }
}
