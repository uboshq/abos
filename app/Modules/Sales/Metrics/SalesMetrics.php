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
            value: fn () => self::net($from, $to), // ⭐ নিট বিক্রি — VAT ও ফেরত বাদে (২০ অক্টোবর ২০২৬)
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
            value: fn () => self::net($from, $to), // ⭐ নিট বিক্রি — VAT ও ফেরত বাদে (২০ অক্টোবর ২০২৬)
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
     * ⭐ নিট বিক্রি — IFRS 15 (fe, ২০ অক্টোবর ২০২৬): আয় ফেরত বাদে (১৫.৫১) **আর ভ্যাট বাদে** (১৫.৪৭ — তৃতীয় পক্ষের হয়ে তোলা টাকা আয় নয়)।
     *   billed   = পাকা বিলের `total − tax − freight_charge` (ছাড় বাদে, রাউন্ডিংসহ; ভ্যাট আর বিলে যোগ করা গাড়িভাড়া বাদে)
     *   returned = পাকা বিক্রয় ফেরতের `total − tax`, **ফেরতের নিজের তারিখে**
     *   net      = billed − returned  ("নিট বিক্রি")
     *   vat      = বিলের ভ্যাট − ফেরতের ভ্যাট (আলাদা দেখানো, আয় নয়)
     *   gross    = net + vat = "VAT সহ মোট (আদায়যোগ্য)" — বিক্রির অংশে গ্রাহকের কাছে পাওনা (গাড়িভাড়া বাদে)
     * ⛔ ফেরত বিলের তারিখে নয়: গতকালের ফেরত আজকের বিক্রি কমায় না, আজকের ফেরত গত মাসের বিলেরও হলে আজই কমায়।
     * ⓘ দেয়াল দুই দিকেই একই — কোম্পানি, শাখা/হেডারের শাখা ([[ScopedToUserBranch]]), বিক্রয়কর্মীর ডিলার
     * ([[ScopedToUserDealers]]), আর হোমের ছাঁকনি। ⓘ সব bcmath, চার ঘর। ⓘ [[invoiceTotal()]] আগের মতোই ভ্যাটসহ বিল।
     *
     * @return array{billed: string, returned: string, net: string, vat: string, gross: string}
     */
    public static function netParts(string $from, string $to, ?array $customerIds = null): array
    {
        [$billed, $billedVat] = self::exVat(self::invoices($from, $to, $customerIds), 'sal_invoices');
        [$returned, $returnedVat] = self::exVat(self::returns($from, $to, $customerIds), 'sal_returns');

        return self::parts($billed, $billedVat, $returned, $returnedVat);
    }

    public static function billed(string $from, string $to, ?array $customerIds = null): string
    {
        return self::exVat(self::invoices($from, $to, $customerIds), 'sal_invoices')[0];
    }

    public static function returned(string $from, string $to, ?array $customerIds = null): string
    {
        return self::exVat(self::returns($from, $to, $customerIds), 'sal_returns')[0];
    }

    public static function net(string $from, string $to, ?array $customerIds = null): string
    {
        return self::netParts($from, $to, $customerIds)['net'];
    }

    /** ভ্যাট — বিলের ভ্যাট বাদ ফেরতের ভ্যাট; আয় নয়, আলাদা দেখানো */
    public static function vat(string $from, string $to, ?array $customerIds = null): string
    {
        return self::netParts($from, $to, $customerIds)['vat'];
    }

    /**
     * নিটের ভেতরের ভাগ — "বিল ৳… − ফেরত ৳… = নিট বিক্রি ৳…", আর আলাদা লাইনে "VAT সহ মোট (আদায়যোগ্য) ৳…";
     * কার্ডের খোলা-ঘরে (টুলটিপ) বসে। ⓘ অঙ্কগুলো [[netParts()]] — কার্ডের সংখ্যার একই উৎস।
     */
    public static function netPartsLine(string $from, string $to): string
    {
        $p = self::netParts($from, $to);

        return __('sales::dashboard.net_parts', [
            'billed' => Money::format($p['billed']),
            'returned' => Money::format($p['returned']),
            'net' => Money::format($p['net']),
        ])."\n".__('sales::dashboard.net_with_vat', [
            'gross' => Money::format($p['gross']),
            'vat' => Money::format($p['vat']),
        ]);
    }

    /**
     * ⓘ শাখা ধরে নিট — দুই কোয়েরি (বিল আর ফেরত, প্রতিটা একটা `GROUP BY`), শাখা ধরে লুপ নয়। চাবি শাখার আইডি, শাখাহীন `0`।
     * ⛔ সারির `net`-এর যোগ = [[net()]] পয়সায় — যে শাখায় কেবল ফেরত, সেটাও সারি পায়।
     *
     * @return array<int, array{billed: string, returned: string, net: string, vat: string, gross: string}>
     */
    public static function netByBranch(string $from, string $to): array
    {
        $billed = self::exVatByBranch(self::invoices($from, $to), 'sal_invoices');
        $returned = self::exVatByBranch(self::returns($from, $to), 'sal_returns');
        $out = [];

        foreach (array_unique([...array_keys($billed), ...array_keys($returned)]) as $branch) {
            $out[(int) $branch] = self::parts(
                $billed[$branch][0] ?? '0.0000', $billed[$branch][1] ?? '0.0000',
                $returned[$branch][0] ?? '0.0000', $returned[$branch][1] ?? '0.0000',
            );
        }

        return $out;
    }

    /**
     * ⭐ দিন ধরে নিট বিক্রি — কার্ডের সাত দিনের রেখা আর গত সপ্তাহের তুলনা (২০ অক্টোবর ২০২৬)। দুই কোয়েরি, `GROUP BY trx_date`;
     * খালি দিন "0.0000"। ⛔ প্রতিটা দিন = [[net()]] ঐ দিনের, পয়সায়।
     *
     * @return array<string, string> তারিখ (Y-m-d) → নিট
     */
    public static function netByDay(string $from, string $to): array
    {
        $billed = self::exVatGrouped(self::invoices($from, $to), 'sal_invoices', 'DATE(sal_invoices.trx_date)');
        $returned = self::exVatGrouped(self::returns($from, $to), 'sal_returns', 'DATE(sal_returns.trx_date)');
        $out = [];

        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $key = $day->toDateString();
            $out[$key] = bcsub($billed[$key][0] ?? '0.0000', $returned[$key][0] ?? '0.0000', 4);
        }

        return $out;
    }

    /**
     * ⭐ মাস ধরে নিট বিক্রি — বিক্রয়ের সারাংশের বারো মাসের ঘর (২০ অক্টোবর ২০২৬)। দুই কোয়েরি, `GROUP BY` মাস।
     * ⛔ প্রতিটা মাস = [[netParts()]] ঐ মাসের, পয়সায়।
     *
     * @return array<string, array{billed: string, returned: string, net: string, vat: string, gross: string}> `Y-m` → ভাগ (কেবল যে মাসে কিছু আছে)
     */
    public static function netByMonth(string $from, string $to): array
    {
        $billed = self::exVatGrouped(self::invoices($from, $to), 'sal_invoices', "DATE_FORMAT(sal_invoices.trx_date, '%Y-%m')");
        $returned = self::exVatGrouped(self::returns($from, $to), 'sal_returns', "DATE_FORMAT(sal_returns.trx_date, '%Y-%m')");
        $out = [];

        foreach (array_unique([...array_keys($billed), ...array_keys($returned)]) as $ym) {
            $out[(string) $ym] = self::parts(
                $billed[$ym][0] ?? '0.0000', $billed[$ym][1] ?? '0.0000',
                $returned[$ym][0] ?? '0.0000', $returned[$ym][1] ?? '0.0000',
            );
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: string}> দল → [নিট, ভ্যাট] */
    private static function exVatGrouped(\Illuminate\Database\Eloquent\Builder $query, string $table, string $expr): array
    {
        $out = [];

        foreach ($query->selectRaw("{$expr} as k, COALESCE(SUM(".self::netSql($table).'), 0) as ex_vat, COALESCE(SUM('.$table.'.tax), 0) as vat')
            ->groupByRaw($expr)->toBase()->get() as $row) {
            $key = $row->k instanceof \DateTimeInterface ? $row->k->format('Y-m-d') : substr((string) $row->k, 0, strlen((string) $row->k) > 7 ? 10 : 7);
            $out[$key] = [bcadd((string) $row->ex_vat, '0', 4), bcadd((string) $row->vat, '0', 4)];
        }

        return $out;
    }

    /** @return array{billed: string, returned: string, net: string, vat: string, gross: string} */
    private static function parts(string $billed, string $billedVat, string $returned, string $returnedVat): array
    {
        $net = bcsub($billed, $returned, 4);
        $vat = bcsub($billedVat, $returnedVat, 4);

        return ['billed' => $billed, 'returned' => $returned, 'net' => $net, 'vat' => $vat, 'gross' => bcadd($net, $vat, 4)];
    }

    /**
     * ⭐ বিলের নিট বিক্রির SQL — `total − tax − freight_charge`: ভ্যাট বাদে (IFRS 15.47), আর বিলে যোগ করা গাড়িভাড়াও বাদ —
     * ওটা খাতায় আলাদা আয় ([[StandardChart::FREIGHT_INCOME]]), পণ্যের বিক্রি নয়; সারি-ধরা নিট ([[LineNetSale]]) আর খাতার
     * বিক্রির হিসাবের সাথে হুবহু। ⓘ লক্ষ্যের অর্জনও এটাই পড়ে ([[SalesTargetService::achievedByUser()]])।
     */
    public static function billNetSql(string $table): string
    {
        return "({$table}.total - {$table}.tax - {$table}.freight_charge)";
    }

    /** ⭐ ফেরতের নিট বিক্রির SQL — `total − tax` (ফেরতে গাড়িভাড়া নেই, [[AReturnDoesNotRefundTheFreightTest]]) */
    public static function returnNetSql(string $table): string
    {
        return "({$table}.total - {$table}.tax)";
    }

    /**
     * একটা কোয়েরিতে ভ্যাট-বাদ অঙ্ক আর ভ্যাট।
     *
     * @return array{0: string, 1: string}
     */
    private static function exVat(\Illuminate\Database\Eloquent\Builder $query, string $table): array
    {
        $row = $query->selectRaw('COALESCE(SUM('.self::netSql($table).'), 0) as ex_vat, COALESCE(SUM('.$table.'.tax), 0) as vat')
            ->toBase()->first();

        return [bcadd((string) ($row->ex_vat ?? '0'), '0', 4), bcadd((string) ($row->vat ?? '0'), '0', 4)];
    }

    private static function netSql(string $table): string
    {
        return $table === 'sal_returns' ? self::returnNetSql($table) : self::billNetSql($table);
    }

    /** @return array<int, array{0: string, 1: string}> শাখা → [ভ্যাট-বাদ, ভ্যাট] */
    private static function exVatByBranch(\Illuminate\Database\Eloquent\Builder $query, string $table): array
    {
        $out = [];

        foreach ($query->selectRaw($table.'.branch_id as branch_id, COALESCE(SUM('.self::netSql($table).'), 0) as ex_vat, COALESCE(SUM('.$table.'.tax), 0) as vat')
            ->groupBy("{$table}.branch_id")->toBase()->get() as $row) {
            $out[(int) $row->branch_id] = [bcadd((string) $row->ex_vat, '0', 4), bcadd((string) $row->vat, '0', 4)];
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
