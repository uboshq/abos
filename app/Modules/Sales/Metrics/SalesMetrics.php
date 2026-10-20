<?php

declare(strict_types=1);

namespace App\Modules\Sales\Metrics;

use App\Core\Contracts\ProvidesMetrics;
use App\Core\Dashboard\HomeFilter;
use App\Core\Metrics\Metric;
use App\Core\Services\DealerScope;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;

/**
 * বিক্রয়ের সংখ্যাগুলোর সংজ্ঞা — একটাই জায়গা।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * "আজকের বিক্রয়" এই রিপোতেই চার জায়গায় হিসাব হত: হোম পর্দা, কাউন্টার,
 * রিপোর্ট, শিফট। একবার তারা দুইটা আলাদা উত্তর দিয়েছিল — `todaysTotal()`
 * খসড়াও গুনত, তাই ধরে-রাখা একটা বিলের টাকা ক্যাশিয়ারের ঘরে যোগ হয়ে
 * বসে থাকত, অথচ ড্রয়ারে আসেনি।
 *
 * ── "আজকের বিক্রয়" আসলে দুইটা প্রশ্ন ────────────────────────────────
 * `sales.today` — গোটা কোম্পানির আজ।
 * `sales.today_at_my_counter` — এই ক্যাশিয়ারের আজ, শিফট মেলানোর জন্য।
 *
 * এই দুইটা সংখ্যা প্রায়ই আলাদা, আর সেটাই ঠিক। আগে দুইটার কোথাও লেখা
 * ছিল না যে তারা আলাদা প্রশ্নের উত্তর — তাই না মিললে মনে হত কোথাও ভুল।
 * এখন দুইটাই নিজের সংজ্ঞা নিজে বলে।
 */
final class SalesMetrics implements ProvidesMetrics
{
    /** @return array<string, Metric> */
    public static function metrics(): array
    {
        $out = [];

        foreach ([
            self::salesToday(),
            self::salesThisMonth(),
            self::collectedToday(),
            self::collectedThisMonth(),
            self::salesThisYear(),
            self::collectedThisYear(),
            self::salesTodayAtMyCounter(),
        ] as $metric) {
            $out[$metric->key] = $metric;
        }

        return $out;
    }

    /**
     * কোম্পানির আজকের **নিট** বিক্রয় — আজকের পাকা বিল বাদ আজকের পাকা ফেরত ([[net()]]; IFRS 15, fe ২০ অক্টোবর ২০২৬)।
     * ⓘ বিল আর ফেরত আলাদা চাইলে [[billed()]] আর [[returned()]] — একই দিন, একই দেয়াল। ⓘ চাবিটা আগের মতোই `sales.today`।
     */
    public static function salesToday(): Metric
    {
        $today = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.today',
            label: __('sales::dashboard.sales_today'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.invoice.view',
            value: fn () => self::net($today, $today),
        );
    }

    /** চলতি মাসের বিক্রয়, মাসের ১ তারিখ থেকে আজ পর্যন্ত। */
    public static function salesThisMonth(): Metric
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.month',
            label: __('sales::dashboard.sales_this_month'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.invoice.view',
            value: fn () => self::invoiceTotal($from, $to),
        );
    }

    /** আজ যত টাকা আদায় হয়েছে। */
    public static function collectedToday(): Metric
    {
        $today = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.collected_today',
            label: __('sales::dashboard.collected_today'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.collection.view',
            value: fn () => self::collectionTotal($today, $today),
        );
    }

    /** চলতি মাসের আদায়। */
    public static function collectedThisMonth(): Metric
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.collected_month',
            label: __('sales::dashboard.collected_this_month'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.collection.view',
            value: fn () => self::collectionTotal($from, $to),
        );
    }

    /**
     * চলতি অর্থবছরের বিক্রয়।
     *
     * ── কেন ক্যালেন্ডার বছর নয় ──────────────────────────────────────
     * ব্যবসার বছর জুলাই থেকে জুন। জানুয়ারি ধরে গুনলে সংখ্যাটা হিসাবের
     * খাতার সাথে মিলত না, আর মালিক দুইটা "এই বছর" নিয়ে বসতেন — একটা
     * পর্দায়, একটা হিসাবরক্ষকের কাছে।
     */
    public static function salesThisYear(): Metric
    {
        [$from, $to] = self::financialYear();

        return new Metric(
            key: 'sales.year',
            label: __('sales::dashboard.sales_this_year'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.invoice.view',
            value: fn () => self::invoiceTotal($from, $to),
        );
    }

    /** চলতি অর্থবছরের আদায়। */
    public static function collectedThisYear(): Metric
    {
        [$from, $to] = self::financialYear();

        return new Metric(
            key: 'sales.collected_year',
            label: __('sales::dashboard.collected_this_year'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.collection.view',
            value: fn () => self::collectionTotal($from, $to),
        );
    }

    /**
     * চলতি অর্থবছরের দুই প্রান্ত।
     *
     * বছরটা কোম্পানির নিজের সারি থেকেই আসে — কোথাও জুলাই-জুন লেখা
     * হয় না। কোনো বছর খোলা না থাকলে চলতি মাসটাই ধরা হয়, কারণ শূন্য
     * দেখানোর চেয়ে কম দেখানো ভালো — আর নতুন কোম্পানিতে সেটাই সত্যি।
     *
     * @return array{0: string, 1: string}
     */
    public static function financialYear(): array
    {
        $year = auth()->user()?->currentCompany?->currentFinancialYear();

        return $year === null
            ? [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()]
            : [$year->starts_on->toDateString(), Carbon::today()->toDateString()];
    }

    /**
     * এই ক্যাশিয়ারের আজ — কাউন্টারের পর্দার উপরে।
     *
     * ── কেন এটা `sales.today` নয় ────────────────────────────────────
     * ক্যাশিয়ার দিনশেষে নিজের ড্রয়ার মেলান, দোকানের মোট বিক্রয় নয়।
     * দুইজন ক্যাশিয়ার থাকলে সংখ্যা দুইটা আলাদা হবেই — আর সেটা ভুল নয়,
     * ভিন্ন প্রশ্ন। একই নামে রাখলে কেউ একদিন বলত "হোম পর্দায় এক, এখানে
     * আরেক", আর কোনটা ঠিক তা বলার কিছু থাকত না।
     */
    public static function salesTodayAtMyCounter(): Metric
    {
        $today = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.today_at_my_counter',
            label: __('sales::message.pos_today'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.pos',
            value: fn () => Money::of(SalesInvoice::query()
                ->posted()
                ->whereDate('trx_date', $today)
                ->where('created_by', auth()->id())
                ->sum('total')),
        );
    }

    public static function invoiceTotal(string $from, string $to, ?array $customerIds = null): string
    {
        return Money::of(self::invoices($from, $to, $customerIds)->sum('total'));
    }

    /**
     * ⭐ [[invoiceTotal()]] শাখা ধরে — একই বিল, একই দেয়াল, এক কোয়েরিতে `GROUP BY branch_id` (ফোনের হোমের
     * "আজকের বিক্রি" কার্ড, অ্যাপ ০.৪.৪১, ২০ অক্টোবর ২০২৬)। ⓘ চাবি শাখার আইডি, শাখাহীন বিল `0`-তে;
     * কেবল যে শাখায় বিক্রি আছে। ⛔ সারিগুলোর যোগ = [[invoiceTotal()]] হুবহু — দুইটাই নিচের একই ভিত থেকে।
     *
     * @return array<int, string>
     */
    public static function invoiceTotalByBranch(string $from, string $to): array
    {
        $out = [];

        foreach (self::invoices($from, $to)
            ->selectRaw('sal_invoices.branch_id as branch_id, COALESCE(SUM(sal_invoices.total), 0) as amount')
            ->groupBy('sal_invoices.branch_id')
            ->toBase()
            ->get() as $row) {
            $out[(int) $row->branch_id] = Money::of((string) $row->amount);
        }

        return $out;
    }

    /**
     * ⭐ নিট বিক্রি — IFRS 15: আয় ফেরত বাদে (fe, ২০ অক্টোবর ২০২৬)। তিনটা সংখ্যা, একটাই ভিত:
     *   billed   = পাকা বিলের `total` ([[invoiceTotal()]] হুবহু — ভ্যাটসহ, ছাড় বাদে, রাউন্ডিংসহ)
     *   returned = পাকা বিক্রয় ফেরতের `total`, **ফেরতের নিজের তারিখে** (subtotal + tax — বিলের দিকের একই ভ্যাটসহ রীতি)
     *   net      = billed − returned
     * ⛔ ফেরত বিলের তারিখে নয়: গতকালের ফেরত আজকের বিক্রি কমায় না, আজকের ফেরত গত মাসের বিলেরও হলে আজই কমায়।
     * ⓘ দেয়াল দুই দিকেই একই — কোম্পানি, শাখা/হেডারের শাখা ([[ScopedToUserBranch]]), বিক্রয়কর্মীর ডিলার
     * ([[ScopedToUserDealers]]), আর হোমের ছাঁকনি। ⓘ সব bcmath, চার ঘর।
     */
    public static function billed(string $from, string $to, ?array $customerIds = null): string
    {
        return bcadd(self::invoiceTotal($from, $to, $customerIds), '0', 4);
    }

    public static function returned(string $from, string $to, ?array $customerIds = null): string
    {
        return bcadd((string) (self::returns($from, $to, $customerIds)->sum('sal_returns.total') ?: '0'), '0', 4);
    }

    public static function net(string $from, string $to, ?array $customerIds = null): string
    {
        return bcsub(self::billed($from, $to, $customerIds), self::returned($from, $to, $customerIds), 4);
    }

    /**
     * নিটের ভেতরের ভাগ, এক লাইনে — "বিল ৳… − ফেরত ৳… = নিট ৳…"; কার্ডের খোলা-ঘরে (টুলটিপ) বসে।
     * ⓘ তিনটা অঙ্কই উপরের একই তিন পদ্ধতি, তাই কার্ডের সংখ্যা আর এই লাইনের নিট পয়সায় এক।
     */
    public static function netPartsLine(string $from, string $to): string
    {
        $billed = self::billed($from, $to);
        $returned = self::returned($from, $to);

        return __('sales::dashboard.net_parts', [
            'billed' => Money::format($billed),
            'returned' => Money::format($returned),
            'net' => Money::format(bcsub($billed, $returned, 4)),
        ]);
    }

    /** @return array<int, string> শাখার আইডি → বিল (শাখাহীন `0`) */
    public static function billedByBranch(string $from, string $to): array
    {
        return array_map(fn (string $v): string => bcadd($v, '0', 4), self::invoiceTotalByBranch($from, $to));
    }

    /** @return array<int, string> শাখার আইডি → ফেরত, ফেরতের নিজের শাখা আর তারিখে (শাখাহীন `0`); এক কোয়েরি */
    public static function returnedByBranch(string $from, string $to): array
    {
        $out = [];

        foreach (self::returns($from, $to)
            ->selectRaw('sal_returns.branch_id as branch_id, COALESCE(SUM(sal_returns.total), 0) as amount')
            ->groupBy('sal_returns.branch_id')
            ->toBase()
            ->get() as $row) {
            $out[(int) $row->branch_id] = bcadd((string) $row->amount, '0', 4);
        }

        return $out;
    }

    /**
     * ⓘ শাখা ধরে নিট — দুই কোয়েরি (বিল আর ফেরত, প্রতিটা একটা `GROUP BY`), শাখা ধরে লুপ নয়।
     * ⛔ সারির যোগ = [[net()]] পয়সায় — যে শাখায় কেবল ফেরত, সেটাও সারি পায়।
     *
     * @return array<int, array{billed: string, returned: string, net: string}>
     */
    public static function netByBranch(string $from, string $to): array
    {
        $billed = self::billedByBranch($from, $to);
        $returned = self::returnedByBranch($from, $to);
        $out = [];

        foreach (array_unique([...array_keys($billed), ...array_keys($returned)]) as $branch) {
            $b = $billed[$branch] ?? '0.0000';
            $r = $returned[$branch] ?? '0.0000';
            $out[(int) $branch] = ['billed' => $b, 'returned' => $r, 'net' => bcsub($b, $r, 4)];
        }

        return $out;
    }

    /**
     * ফেরতের ভিত — পাকা ফেরত, ফেরতের তারিখে, মডেলের দেয়াল আর হোমের ছাঁকনি; [[invoices()]]-এর জোড়া।
     *
     * @return \Illuminate\Database\Eloquent\Builder<SalesReturn>
     */
    private static function returns(string $from, string $to, ?array $customerIds = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = SalesReturn::query()
            ->posted()
            ->whereBetween('sal_returns.trx_date', [$from, $to])
            ->when($customerIds !== null, fn ($q) => $q->whereIn('sal_returns.customer_id', $customerIds ?: [0]));

        HomeFilter::current()?->onReturns($query, 'sal_returns');

        return $query;
    }

    /**
     * বিক্রয়ের একটাই ভিত — খাতায় বসা বিল, সময়কালে, মডেলের দেয়ালসহ (কোম্পানি, শাখা, বিক্রয়কর্মীর ডিলার) আর হোমের ছাঁকনি।
     *
     * @return \Illuminate\Database\Eloquent\Builder<SalesInvoice>
     */
    private static function invoices(string $from, string $to, ?array $customerIds = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = SalesInvoice::query()
            ->posted()
            ->whereBetween('trx_date', [$from, $to])
            /*
             * ⓘ কয়েকজন গ্রাহকের ভাগ — মালিকের কেন্দ্রে ভাই-কোম্পানির বিক্রি বাদ দিতে ([[SisterTrade]], ৮ অক্টোবর ২০২৬)।
             * ⓘ একই সংজ্ঞা, কেবল গ্রাহকে ছাঁকা — তাই বাদ দেওয়া অংশ কখনো মোটের চেয়ে অন্য নিয়মে গোনা হয় না।
             */
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds ?: [0]));

        // ⭐ হোমের ছাঁকনি (গুদাম/এলাকা/SR) — কেবল হোমের সংখ্যা গোনার সময়টুকু চালু ([[HomeFilter::during()]], ৪ অক্টোবর ২০২৬)
        HomeFilter::current()?->onInvoices($query, 'sal_invoices');

        return $query;
    }

    /**
     * গ্রাহকের কাছ থেকে আসা টাকা — আদায়ের কাগজ **আর** গ্রাহকের রসিদ ভাউচার।
     *
     * ── ⭐ কেন ভাউচারও, ১৯ সেপ্টেম্বর ২০২৬ ──────────────────────────────
     * মালিকের নিয়মে কাউন্টারের সব ডিপোজিট এখন রসিদ ভাউচার। ⛔ কেবল আদায়ের
     * কাগজ গুনলে কাউন্টারের টাকা ড্যাশবোর্ড থেকে উধাও হত — আর হিসাব থেকে
     * হাতে কাটা গ্রাহকের রসিদ আগেও বাদ পড়ত।
     *
     * ⓘ কেবল খাতায় বসা, কেবল গ্রাহক পক্ষ — সরবরাহকারীর ফেরত বা অন্য আয়
     * বিক্রয়ের আদায় নয়। ⓘ ড্যাশবোর্ডের মাসিক চার্টও এটাই ডাকে, যাতে
     * কার্ড আর চার্ট দুই অঙ্ক না বলে।
     */
    public static function collectionTotal(string $from, string $to): string
    {
        $byCollection = (string) (Collection::query()
            ->posted()
            ->whereBetween('trx_date', [$from, $to])
            ->sum('amount') ?: '0');

        $byVoucher = (string) (Voucher::query()
            ->where('type', Voucher::RECEIPT)
            ->where('party_type', 'customer')
            // ⭐ বিক্রয়কর্মী কেবল নিজের ডিলারের রসিদ গোনেন (⛔১৬, ২ অক্টোবর ২০২৬)
            ->tap(fn ($q) => app(DealerScope::class)->restrict($q, 'party_id'))
            ->posted()
            ->whereBetween('trx_date', [$from, $to])
            ->sum('amount') ?: '0');

        return Money::of(bcadd($byCollection, $byVoucher, 4));
    }

    /**
     * ⭐ কয়টা আদায় — [[collectionTotal()]]-এর হুবহু দুই উৎস আর একই দেয়াল (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️৯: ফোনের
     * আজকের পাতায় রসিদ-ভাউচারের গোনা ডিলার-দেয়াল ছাড়া ছিল — বাঁধা বিক্রয়কর্মী শাখার সব ডিলারের রসিদ-সংখ্যা দেখতেন,
     * আর পাশের অঙ্কের সাথে মিলত না)। ⚠️ একটা বদলালে অন্যটাও।
     */
    public static function collectionCount(string $from, string $to): int
    {
        return Collection::query()->posted()->whereBetween('trx_date', [$from, $to])->count()
            + Voucher::query()
                ->where('type', Voucher::RECEIPT)
                ->where('party_type', 'customer')
                ->tap(fn ($q) => app(DealerScope::class)->restrict($q, 'party_id'))
                ->posted()
                ->whereBetween('trx_date', [$from, $to])
                ->count();
    }
}
