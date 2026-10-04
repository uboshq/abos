<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ViewedBranch;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\RouteTarget;
use App\Modules\Sales\Models\RouteVisit;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesTarget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * রুটের খাতা — প্রতিটা রুটে কয়জন ডিলার, কত বিক্রি, কত আদায়, কত বাকি (NEXUS §২৭)।
 *
 * ── ⭐ প্রতিটা অঙ্ক গ্রাহকের খতিয়ান থেকে ──────────────────────────────
 * বাকি মানে গ্রাহকের খাতে ডেবিট − ক্রেডিট — ঠিক [[Customer::outstanding()]]
 * আর [[PartyReports::dueList()]]-এর নিয়ম। ⛔ "বিল বিয়োগ আদায়" হাতে গুনলে
 * খোলা জের, চেক ফেরত, ফেরতের কাগজ, সমন্বয়ের ভাউচার — সব বাদ পড়ত, আর
 * রুটের পাতা ডিলারের পাতার সাথে মিলত না।
 *
 * সময়ের ভিতরের চলাচল উৎস ধরে ভাগ করা (খতিয়ানের `source_type`):
 *
 *     বিক্রয়   sales_invoice*          ডেবিট − ক্রেডিট (বাতিলের উল্টো সারি বাদ যায়)
 *     ফেরত    sales_return*           ক্রেডিট − ডেবিট
 *     আদায়    collection*, receipt_voucher*, payment_voucher*, cheque*
 *                                      ক্রেডিট − ডেবিট (টাকা ফেরত দিলে কমে)
 *     অন্যান্য বাকি সব — খোলা জের, কমিশনের জমা, জার্নাল
 *
 * ⓘ তাই অঙ্কটা সবসময় মেলে: আগের জের + বিক্রয় − ফেরত − আদায় + অন্যান্য
 * = শেষ জের। "অন্যান্য" ঘরটা লুকানো হয় না — না দেখালে ঐ সমীকরণ পর্দায়
 * মিলত না, আর মালিক ভাবতেন হিসাবে ভুল।
 *
 * ── ⭐ রুট মানে ডিলারের **আজকের** রুট ────────────────────────────────
 * খতিয়ানের সারিতে রুট লেখা থাকে না; ডিলার রুটে বসেন `customers.location_id`
 * দিয়ে। ডিলার অন্য রুটে সরলে তাঁর পুরো খাতা — পুরনো বিল আর বকেয়াসহ —
 * নতুন রুটে যায়। ⓘ এটাই ঠিক: বকেয়া তুলবেন নতুন রুটের লোক (বাঁধনের নকশা
 * §খ৪, "কে দেখবেন")। "কার বিক্রি" প্রশ্নটা আলাদা — সেটা কাগজের ছাপে
 * (`sales_rep_id`, বাঁধনের কাজ), রুটের এই খাতায় নয়।
 */
final class RouteMetrics
{
    /** @var list<string> পর্দা আর পরীক্ষা এই ক্রমেই পড়ে */
    public const FIGURES = ['opening', 'sales', 'returns', 'collections', 'other', 'outstanding'];

    public function __construct(private readonly SalesTargetService $targets) {}

    // ── উৎসের পরিবার ─────────────────────────────────────────────────────

    /** @return list<string> */
    public static function salesSources(): array
    {
        return [SalesInvoice::drillSourceType()];
    }

    /** @return list<string> */
    public static function returnSources(): array
    {
        return [SalesReturn::drillSourceType()];
    }

    /**
     * টাকা আসা-যাওয়ার কাগজ।
     *
     * ⓘ কাউন্টারের জমা রসিদ-ভাউচারে বসে ([[DirectSaleService]]), জমার দাবি
     * গ্রহণ হলে আদায় হয় ([[DepositClaimService::accept()]]), আর কাউন্টারের
     * টাকা ফেরত প্রদান-ভাউচারে — ওটা আদায় কমায়, নতুন বাকি নয়।
     *
     * @return list<string>
     */
    public static function moneySources(): array
    {
        return [
            Collection::drillSourceType(),
            Voucher::SOURCE_TYPES[Voucher::RECEIPT],
            Voucher::SOURCE_TYPES[Voucher::PAYMENT],
            Cheque::STOCK_SOURCE,
        ];
    }

    // ── খতিয়ানের প্রশ্ন ──────────────────────────────────────────────────

    /**
     * রুট ধরে খতিয়ানের যোগফল — রিপোর্ট আর পর্দা দুইটাই এটাই ডাকে।
     *
     * ⓘ এক সংজ্ঞা, দুই জায়গা: রিপোর্ট এটাকে `joinSub` করে, পর্দা সরাসরি
     * চালায়। দুইবার লিখলে একদিন দুইটা দুই অঙ্ক দেখাত।
     */
    public function ledgerByRoute(string $from, string $to, ?int $branchId = null, ?int $companyId = null): Builder
    {
        return $this->ledgerBase($from, $to, $branchId, $companyId)
            ->groupBy('customers.location_id')
            ->select(['customers.location_id as route_id', ...$this->sums($from)]);
    }

    /** গ্রাহক ধরে — রুটের পাতার সারি, আর রুটের যোগফল মেলানোর পরীক্ষা। */
    public function ledgerByCustomer(string $from, string $to, ?int $branchId = null, ?int $companyId = null): Builder
    {
        return $this->ledgerBase($from, $to, $branchId, $companyId)
            ->groupBy('ledger_entries.party_id')
            ->select(['ledger_entries.party_id as customer_id', ...$this->sums($from)]);
    }

    /**
     * রুট প্রতি অঙ্ক — যাদের খাতায় কিছু নেই তাদেরও শূন্যসহ।
     *
     * @param  list<int>  $routeIds
     * @return array<int, array<string, string|int>>
     */
    public function forRoutes(array $routeIds, string $from, string $to, ?int $branchId = null): array
    {
        $out = [];

        foreach ($routeIds as $id) {
            $out[(int) $id] = $this->blank() + ['customers' => 0];
        }

        if ($routeIds === []) {
            return $out;
        }

        $rows = $this->ledgerByRoute($from, $to, $branchId)
            ->whereIn('customers.location_id', $routeIds)
            ->get();

        foreach ($rows as $row) {
            $out[(int) $row->route_id] = $this->figures($row) + ['customers' => 0];
        }

        $counts = Customer::query()
            ->whereIn('location_id', $routeIds)
            ->groupBy('location_id')
            ->selectRaw('location_id, COUNT(*) as n')
            ->get();

        foreach ($counts as $count) {
            $out[(int) $count->location_id]['customers'] = (int) $count->n;
        }

        return $out;
    }

    /**
     * গ্রাহক প্রতি অঙ্ক।
     *
     * @param  list<int>  $customerIds
     * @return array<int, array<string, string>>
     */
    public function forCustomers(array $customerIds, string $from, string $to, ?int $branchId = null): array
    {
        $out = [];

        foreach ($customerIds as $id) {
            $out[(int) $id] = $this->blank();
        }

        if ($customerIds === []) {
            return $out;
        }

        $rows = $this->ledgerByCustomer($from, $to, $branchId)
            ->whereIn('ledger_entries.party_id', $customerIds)
            ->get();

        foreach ($rows as $row) {
            $out[(int) $row->customer_id] = $this->figures($row);
        }

        return $out;
    }

    // ── কে যান, কত করার কথা ─────────────────────────────────────────────

    /**
     * এই তারিখে কে কে রুটে যান — ছক থেকে, বাঁধন থেকে নয়।
     *
     * ⓘ বিক্রয়কর্মী↔ডিলারের বাঁধন আলাদা কাজ; রুটের লোক বলতে এখানে সাপ্তাহিক
     * ছকে যাঁর নাম চালু আছে।
     *
     * @param  list<int>  $routeIds
     * @return array<int, list<string>> রুট => নাম (বর্ণক্রমে, একজন একবার)
     */
    public function salespeopleOn(array $routeIds, Carbon|string $date): array
    {
        $out = array_fill_keys(array_map('intval', $routeIds), []);

        if ($routeIds === []) {
            return $out;
        }

        $visits = RouteVisit::query()
            ->with('user:id,name')
            ->whereIn('route_id', $routeIds)
            ->activeOn($date)
            ->get();

        foreach ($visits->groupBy('route_id') as $routeId => $rows) {
            $out[(int) $routeId] = $rows->map(fn (RouteVisit $v) => (string) $v->user?->name)
                ->filter()->unique()->sort()->values()->all();
        }

        return $out;
    }

    /**
     * মাসের লক্ষ্য আর অর্জন — রুট প্রতি।
     *
     * ── অর্জনের সংজ্ঞা [[SalesTargetService::achievedByUser()]]-এর ────────
     * নিশ্চিত বিল, মাসের তারিখে, **ভ্যাট বাদ** — কেবল ভাগটা মানুষের বদলে
     * রুট ধরে। ⛔ লক্ষ্যের দুই পর্দায় "অর্জন" দুই রকম মানে হলে একই মাসে
     * মানুষের যোগফল আর রুটের যোগফল কখনো মিলত না। তাই এটা বিলের লাইন থেকে,
     * খতিয়ান থেকে নয় — খতিয়ানে ভ্যাটসহ অঙ্ক বসে।
     *
     * @param  list<int>  $routeIds
     * @return array<int, array{target: ?string, achieved: string, percent: ?string}>
     */
    public function targetsFor(array $routeIds, Carbon|string $month): array
    {
        $first = Carbon::parse(SalesTarget::monthOf($month));
        $last = $first->copy()->endOfMonth();

        $out = [];

        foreach ($routeIds as $id) {
            $out[(int) $id] = ['target' => null, 'achieved' => '0.0000', 'percent' => null];
        }

        if ($routeIds === []) {
            return $out;
        }

        $targets = RouteTarget::query()
            ->whereIn('route_id', $routeIds)
            ->whereDate('month', $first->toDateString())
            ->get();

        foreach ($targets as $target) {
            $out[(int) $target->route_id]['target'] = (string) $target->amount;
        }

        $achieved = DB::table('sal_invoices as i')
            ->join('sal_invoice_lines as il', 'il.sales_invoice_id', '=', 'i.id')
            ->join('customers as cu', 'cu.id', '=', 'i.customer_id')
            ->where('i.company_id', CompanyContext::id())
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->whereBetween('i.trx_date', [$first->toDateString(), $last->toDateString()])
            ->whereIn('cu.location_id', $routeIds)
            ->groupBy('cu.location_id')
            ->selectRaw('cu.location_id as route_id, SUM(il.amount - il.tax) as achieved')
            ->get();

        foreach ($achieved as $row) {
            $out[(int) $row->route_id]['achieved'] = bcadd((string) $row->achieved, '0', 4);
        }

        foreach ($out as $id => $row) {
            $out[$id]['percent'] = $this->targets->percent($row['achieved'], $row['target']);
        }

        return $out;
    }

    /**
     * যে ডিলাররা কোনো রুটে নেই — এলাকাহীন, বা রুটের উপরের ধাপে বসা।
     *
     * ⚠️ এই সংখ্যাটা পর্দায় থাকে, কারণ ডিপোতে দোকান সচরাচর পয়েন্টে বসানো।
     * না দেখালে রুটের খাতা শূন্য দেখাত আর কেউ বুঝত না কেন — মনে হত
     * রুটে বিক্রিই নেই।
     */
    public function unroutedCount(): int
    {
        return Customer::query()
            ->where(fn ($q) => $q->whereNull('location_id')
                ->orWhereHas('location', fn ($l) => $l->where('level', '<>', Location::ROUTE)))
            ->count();
    }

    // ── ভিতরের ───────────────────────────────────────────────────────────

    private function ledgerBase(string $from, string $to, ?int $branchId, ?int $companyId): Builder
    {
        $company = $companyId ?? CompanyContext::id();

        // ⓘ দেখানোর খাতা — হেডারে বাছা শাখা মানে ([[ViewedBranch]], EveryLedgerReaderSaysWhetherItShowsOrChecksTest)
        return ViewedBranch::narrow(DB::table('ledger_entries'), 'ledger_entries.branch_id')
            ->join('customers', 'customers.id', '=', 'ledger_entries.party_id')
            ->where('ledger_entries.company_id', $company)
            ->where('customers.company_id', $company)
            ->where('ledger_entries.party_type', Customer::drillSourceType())
            // শেষ জের "এই তারিখ পর্যন্ত" — তাই শুরুর আগেরগুলোও লাগে (আগের জের)
            ->where('ledger_entries.trx_date', '<=', $to)
            ->when($branchId, fn ($q, $b) => $q->where('ledger_entries.branch_id', $b));
    }

    /**
     * যোগফলের ঘরগুলো — কাঁচা SQL, প্লেসহোল্ডার ছাড়া।
     *
     * ⚠️ SELECT-এর `?` বাকি বাইন্ডিং এক ঘর সরিয়ে দেয় ([[SalesReports]]-এর
     * মাথার মন্তব্য)। তারিখ আর উৎসের নাম PDO দিয়ে উদ্ধৃত — ব্যবহারকারীর
     * লেখা সরাসরি SQL-এ ঢোকে না।
     *
     * @return list<\Illuminate\Database\Query\Expression>
     */
    private function sums(string $from): array
    {
        $start = DB::getPdo()->quote(Carbon::parse($from)->toDateString());
        $in = "ledger_entries.trx_date >= {$start}";
        $before = "ledger_entries.trx_date < {$start}";

        $sales = $this->family(self::salesSources());
        $returns = $this->family(self::returnSources());
        $money = $this->family(self::moneySources());

        return [
            DB::raw("COALESCE(SUM(CASE WHEN {$before} THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END), 0) as opening"),
            DB::raw("COALESCE(SUM(CASE WHEN {$in} AND {$sales} THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END), 0) as sales"),
            DB::raw("COALESCE(SUM(CASE WHEN {$in} AND {$returns} THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END), 0) as returns"),
            DB::raw("COALESCE(SUM(CASE WHEN {$in} AND {$money} THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END), 0) as collections"),
            DB::raw("COALESCE(SUM(CASE WHEN {$in} AND NOT ({$sales} OR {$returns} OR {$money}) THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END), 0) as other"),
            DB::raw('COALESCE(SUM(ledger_entries.debit - ledger_entries.credit), 0) as outstanding'),
        ];
    }

    /**
     * একটা উৎস আর তার উত্তরসূরিরা — `sales_invoice`, `sales_invoice:reversal`, …
     *
     * ⓘ বাতিলের উল্টো সারি `<উৎস>:reversal` নামে বসে ([[PostingEngine::reverse()]]);
     * ওটা একই পরিবারে না ধরলে বাতিল বিলের টাকা "অন্যান্য"-তে গিয়ে বসত।
     *
     * @param  list<string>  $sources
     */
    private function family(array $sources): string
    {
        $pdo = DB::getPdo();
        $parts = [];

        foreach ($sources as $source) {
            $parts[] = 'ledger_entries.source_type = '.$pdo->quote($source);
            $parts[] = 'ledger_entries.source_type LIKE '.$pdo->quote($source.':%');
        }

        return '('.implode(' OR ', $parts).')';
    }

    /** @return array<string, string> */
    private function blank(): array
    {
        return array_fill_keys(self::FIGURES, '0.0000');
    }

    /** @return array<string, string> */
    private function figures(object $row): array
    {
        $out = [];

        foreach (self::FIGURES as $key) {
            $out[$key] = bcadd((string) ($row->{$key} ?? '0'), '0', 4);
        }

        return $out;
    }
}
