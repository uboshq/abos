<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Support\ConductType;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বাকি ও আদায় — বাকি নিয়ন্ত্রণের চার রিপোর্ট (SAP Credit Management / D365 Credit and collections-এর মতো), ৫ অক্টোবর ২০২৬।
 *
 *   · বাকির সীমার ব্যবহার — প্রতিটা গ্রাহক: সীমা, খাতার বকেয়া, আটকে থাকা, অবশিষ্ট, ব্যবহার %।
 *   · বাকি বন্ধের তালিকা — হাতে বসানো "বাকি বন্ধ", কারণ, কে, কবে ([[CustomerService::blockCredit()]])।
 *   · ঝুঁকির গ্রাহক — মেয়াদ পেরোনো পুরনো বাকি, ৯০ দিনে চেক ফেরত, সীমা পার, বা ঝুঁকির আচরণ-নোট।
 *   · বাকির সীমা বদলের ইতিহাস — সীমা বাড়ানোর প্রতিটা সইয়ের অনুরোধ আর তার ফল।
 *
 * ⛔ এক হিসাব, দুই নয়: আটকে থাকা টাকা, ক্লিয়ার না হওয়া চেক আর পুরনো বাকি আসে দেয়ালের নিজের হিসাব থেকে
 * ([[CreditExposure::pendingFor()]], [[CreditExposure::unclearedChequesFor()]], [[CreditExposure::overdueBills()]]) —
 * রিপোর্ট আর কাউন্টার কখনো দুই অঙ্ক বলে না। ⓘ অঙ্কগুলো PHP-তে গোনা, তারপর কোয়েরিতে গ্রাহক-ধরে বসানো
 * ([[perCustomer()]]), যাতে ইঞ্জিন পাতা-ভাগ, মোট আর ফাইল আগের মতোই করে।
 *
 * ⓘ শাখা — গ্রাহকের নিজের শাখা (মানুষের তালিকা, লেনদেনের নয়), কাদের সীমা নেই রিপোর্টের মতোই।
 */
final class CreditControlReports
{
    public const CREDIT_USE = 'sales.credit_use';

    public const BLOCKED_CUSTOMERS = 'sales.credit_blocked_customers';

    public const RISKY_CUSTOMERS = 'sales.credit_risk';

    public const LIMIT_HISTORY = 'sales.credit_limit_history';

    /** ⓘ কোম্পানি পুরনো বাকির দিন না বসালে ঝুঁকির রিপোর্ট এত দিন ধরে দেখে */
    public const RISK_DAYS_FALLBACK = 60;

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::creditUse());
        $engine->register(self::blockedCustomers());
        $engine->register(self::riskyCustomers());
        $engine->register(self::limitHistory());
    }

    public static function creditUse(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::CREDIT_USE,
            permission: 'sales.report',
            title: 'sales::credit.use_title',
            filters: ['branch'],
            query: function (array $f): Builder {
                $ids = self::activeIds($f);
                $held = self::held($ids);
                $heldSql = self::perCustomer($held);
                $due = 'COALESCE(l.due, 0)';
                $limit = 'COALESCE(customers.credit_limit, 0)';

                return self::customers($f)
                    ->orderByRaw("CASE WHEN {$limit} > 0 THEN ({$due} + {$heldSql}) / {$limit} ELSE 0 END DESC")
                    ->orderByRaw("{$due} + {$heldSql} DESC")
                    ->selectRaw("{$limit} as credit_limit, {$due} as outstanding, {$heldSql} as held")
                    ->selectRaw("GREATEST({$limit} - {$due} - {$heldSql}, 0) as available")
                    ->selectRaw("CASE WHEN {$limit} > 0 THEN ROUND(({$due} + {$heldSql}) * 100 / {$limit}, 1) END as used_percent");
            },
            columns: [
                ...self::identityColumns(),
                ['key' => 'credit_limit', 'label' => 'sales::credit.limit', 'type' => ReportColumn::MONEY],
                ['key' => 'outstanding', 'label' => 'sales::credit.outstanding', 'type' => ReportColumn::MONEY],
                ['key' => 'held', 'label' => 'sales::credit.held', 'type' => ReportColumn::MONEY],
                ['key' => 'available', 'label' => 'sales::credit.available', 'type' => ReportColumn::MONEY],
                ['key' => 'used_percent', 'label' => 'sales::credit.used', 'type' => ReportColumn::PERCENT, 'total' => false],
            ],
        );
    }

    public static function blockedCustomers(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::BLOCKED_CUSTOMERS,
            permission: 'sales.report',
            title: 'sales::credit.blocked_title',
            filters: ['branch'],
            query: fn (array $f): Builder => self::customers($f)
                ->whereNotNull('customers.credit_blocked_at')
                ->leftJoin('users as bu', 'bu.id', '=', 'customers.credit_blocked_by')
                ->orderByDesc('customers.credit_blocked_at')
                ->selectRaw('COALESCE(customers.credit_limit, 0) as credit_limit, COALESCE(l.due, 0) as outstanding')
                ->selectRaw('customers.credit_block_reason as reason, bu.name as blocked_by, DATE(customers.credit_blocked_at) as blocked_on'),
            columns: [
                ...self::identityColumns(),
                ['key' => 'outstanding', 'label' => 'sales::credit.outstanding', 'type' => ReportColumn::MONEY],
                ['key' => 'credit_limit', 'label' => 'sales::credit.limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'reason', 'label' => 'sales::credit.reason'],
                ['key' => 'blocked_by', 'label' => 'sales::credit.blocked_by'],
                ['key' => 'blocked_on', 'label' => 'sales::credit.blocked_on', 'type' => ReportColumn::DATE],
            ],
        );
    }

    /**
     * ঝুঁকির গ্রাহক — চার লক্ষণের যেকোনোটা।
     *
     * ⓘ পুরনো বাকি: কোম্পানির দেয়ালের দিন (`customer.overdue_block_days`), না বসানো থাকলে [[RISK_DAYS_FALLBACK]]।
     * ⓘ চেক ফেরত: গত ৯০ দিনে ফেরত যাওয়া চেক (চেকের নিজের পাতায় ফেরত হওয়ার দিনের ঘর নেই, তাই শেষ বদলের দিন)।
     * ⓘ সীমা পার: খাতার বকেয়া + আটকে থাকা (চেকসহ) > সীমা — দেয়ালের মাপ।
     * ⓘ আচরণ: চলমান নোট, যার ধরন "ঝুঁকি" ([[ConductType::RISK]])।
     */
    public static function riskyCustomers(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::RISKY_CUSTOMERS,
            permission: 'sales.report',
            title: 'sales::credit.risk_title',
            filters: ['branch'],
            query: function (array $f): Builder {
                $credit = app(CreditExposure::class);
                $days = $credit->overdueDays() > 0 ? $credit->overdueDays() : self::RISK_DAYS_FALLBACK;

                $customers = Customer::query()
                    ->where('customers.company_id', $f['company_id'])
                    ->where('customers.is_active', true)
                    ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'customers.id')) // ⛔১৬ — বিক্রয়কর্মী কেবল নিজের ডিলার
                    ->withOutstanding()
                    ->get()
                    ->keyBy(fn (Customer $c) => (int) $c->id)
                    ->all();
                $ids = array_keys($customers);

                $overdue = array_map(
                    fn (array $bills) => array_reduce($bills, fn (string $s, array $b) => bcadd($s, $b['unpaid'], 4), '0'),
                    $credit->overdueBills($customers, $days),
                );

                $since = Carbon::today()->subDays(90)->toDateString();
                $bounced = $ids === [] ? [] : DB::table('acc_cheques')
                    ->where('company_id', $f['company_id'])
                    ->whereNull('deleted_at')
                    ->where('direction', 'received')
                    ->where('party_type', 'customer')
                    ->whereIn('party_id', $ids)
                    ->where('status', 'bounced')
                    ->where('updated_at', '>=', $since)
                    ->groupBy('party_id')
                    ->selectRaw('party_id, COUNT(*) as n')
                    ->pluck('n', 'party_id')->map(fn ($n) => (string) $n)->all();

                $riskTypes = array_keys(array_filter(ConductType::TYPES, fn (array $t) => $t[1] === ConductType::RISK));
                $flags = $ids === [] ? [] : DB::table('customer_conduct_notes')
                    ->where('company_id', $f['company_id'])
                    ->whereIn('customer_id', $ids)
                    ->where('is_active', true)
                    ->whereIn('type', $riskTypes)
                    ->groupBy('customer_id')
                    ->selectRaw('customer_id, COUNT(*) as n')
                    ->pluck('n', 'customer_id')->map(fn ($n) => (string) $n)->all();

                $held = self::held($ids);
                $over = [];
                foreach ($customers as $id => $c) {
                    $short = bcsub(bcadd($c->outstanding(), $held[$id] ?? '0', 4), bcadd((string) ($c->credit_limit ?? '0'), '0', 4), 4);
                    if (bccomp($short, '0', 4) > 0) {
                        $over[$id] = $short;
                    }
                }

                $risky = array_values(array_unique(array_map('intval', [
                    ...array_keys($overdue), ...array_keys($bounced), ...array_keys($flags), ...array_keys($over),
                ])));

                return self::customers($f)
                    ->whereIn('customers.id', $risky === [] ? [0] : $risky)
                    ->selectRaw(self::perCustomer($overdue).' as overdue_amount')
                    ->selectRaw(self::perCustomer($bounced).' as bounced_cheques')
                    ->selectRaw(self::perCustomer($over).' as over_limit')
                    ->selectRaw(self::perCustomer($flags).' as risk_flags')
                    ->selectRaw('COALESCE(l.due, 0) as outstanding')
                    ->orderByRaw(self::perCustomer($overdue).' DESC')
                    ->orderByRaw('COALESCE(l.due, 0) DESC');
            },
            columns: [
                ...self::identityColumns(),
                ['key' => 'overdue_amount', 'label' => 'sales::credit.overdue_amount', 'type' => ReportColumn::MONEY],
                ['key' => 'bounced_cheques', 'label' => 'sales::credit.bounced_cheques', 'type' => ReportColumn::QUANTITY],
                ['key' => 'over_limit', 'label' => 'sales::credit.over_limit', 'type' => ReportColumn::MONEY],
                ['key' => 'risk_flags', 'label' => 'sales::credit.risk_flags', 'type' => ReportColumn::QUANTITY],
                ['key' => 'outstanding', 'label' => 'sales::credit.outstanding', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * বাকির সীমা বদলের ইতিহাস — সীমা বাড়ানোর প্রতিটা সইয়ের অনুরোধ ([[CustomerService::assertRaiseIsSigned()]])।
     *
     * ⓘ অঙ্কটা চাওয়া নতুন সীমা; কত থেকে কত, তা অনুরোধের কারণে লেখা থাকে।
     */
    public static function limitHistory(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::LIMIT_HISTORY,
            permission: 'sales.report',
            title: 'sales::credit.history_title',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $status = 'CASE a.status';
                foreach ([Approval::PENDING, Approval::APPROVED, Approval::REJECTED, Approval::CANCELLED] as $s) {
                    $status .= ' WHEN '.$pdo->quote($s).' THEN '.$pdo->quote((string) __('sales::credit.status_'.$s));
                }
                $status .= ' ELSE a.status END';

                return DB::table('approvals as a')
                    ->join('customers', 'customers.id', '=', 'a.approvable_id')
                    ->leftJoin('users as ru', 'ru.id', '=', 'a.requested_by')
                    ->where('a.company_id', $f['company_id'])
                    ->where('customers.company_id', $f['company_id'])
                    ->where('a.approvable_type', (new Customer)->getMorphClass())
                    ->where('a.module', 'customer')
                    ->where('a.action', 'credit_limit')
                    ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'customers.id')) // ⛔১৬ — বিক্রয়কর্মী কেবল নিজের ডিলার
                    ->whereBetween(DB::raw('DATE(a.requested_at)'), [$f['from'], $f['to']])
                    ->orderByDesc('a.requested_at')
                    ->orderByDesc('a.id')
                    ->select(['customers.id', 'customers.code as customer_code'])
                    ->selectRaw(self::name().' as customer_name')
                    ->selectRaw("'".Customer::drillSourceType()."' as party_type_literal")
                    ->selectRaw('DATE(a.requested_at) as requested_on, a.amount as asked_limit')
                    ->selectRaw("{$status} as status")
                    ->selectRaw('ru.name as requested_by, a.requested_reason as reason, DATE(a.decided_at) as decided_on');
            },
            columns: [
                ['key' => 'requested_on', 'label' => 'sales::credit.requested_on', 'type' => ReportColumn::DATE],
                ...self::identityColumns(),
                ['key' => 'asked_limit', 'label' => 'sales::credit.asked_limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'status', 'label' => 'sales::credit.status'],
                ['key' => 'requested_by', 'label' => 'sales::credit.requested_by'],
                ['key' => 'reason', 'label' => 'sales::credit.reason'],
                ['key' => 'decided_on', 'label' => 'sales::credit.decided_on', 'type' => ReportColumn::DATE],
            ],
        );
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** সক্রিয় গ্রাহক, খাতার বকেয়াসহ (`l.due`), শাখার দেয়ালে */
    private static function customers(array $f): Builder
    {
        return DB::table('customers')
            ->leftJoinSub(
                DB::table('ledger_entries')
                    ->where('company_id', $f['company_id'])
                    ->where('party_type', Customer::drillSourceType())
                    ->groupBy('party_id')
                    ->select(['party_id', DB::raw('SUM(debit) - SUM(credit) as due')]),
                'l', 'l.party_id', '=', 'customers.id',
            )
            ->where('customers.company_id', $f['company_id'])
            ->whereNull('customers.deleted_at')
            ->where('customers.is_active', true)
            ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'customers.id')) // ⛔১৬ — বিক্রয়কর্মী কেবল নিজের ডিলার
            ->select(['customers.id', 'customers.code as customer_code'])
            ->selectRaw(self::name().' as customer_name')
            ->selectRaw("'".Customer::drillSourceType()."' as party_type_literal");
    }

    /** @return list<int> */
    private static function activeIds(array $f): array
    {
        return DB::table('customers')
            ->where('customers.company_id', $f['company_id'])
            ->whereNull('customers.deleted_at')
            ->where('customers.is_active', true)
            ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'customers.id')) // ⛔১৬ — বিক্রয়কর্মী কেবল নিজের ডিলার
            ->pluck('customers.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * আটকে থাকা টাকা — দেয়ালের হুবহু ভাগ: বিল না হওয়া চালান, খসড়া বিল, DO, খোলা আদেশ, আর ক্লিয়ার না হওয়া চেক।
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function held(array $ids): array
    {
        $credit = app(CreditExposure::class);
        $out = $credit->pendingFor($ids);

        foreach ($credit->unclearedChequesFor($ids) as $id => $amount) {
            $out[(int) $id] = bcadd($out[(int) $id] ?? '0', $amount, 4);
        }

        return $out;
    }

    /**
     * PHP-তে গোনা গ্রাহক-ধরে অঙ্ক, কোয়েরির একটা ঘর হয়ে — `CASE customers.id WHEN … END`।
     *
     * ⓘ চাবি পূর্ণসংখ্যা, মান bcmath-এর সংখ্যা — দুইটাই এখানে গঠন যাচাই করে বসে, বাইরের লেখা নয়।
     *
     * @param  array<int, string>  $values
     */
    private static function perCustomer(array $values): string
    {
        $cases = '';

        foreach ($values as $id => $value) {
            $number = bcadd((string) $value, '0', 4);
            $cases .= ' WHEN '.(int) $id.' THEN '.$number;
        }

        return $cases === '' ? '(0 + 0)' : "(CASE customers.id{$cases} ELSE 0 END)";
    }

    private static function name(): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(customers.name_bn, ''), customers.name_en)"
            : "COALESCE(NULLIF(customers.name_en, ''), customers.name_bn)";
    }

    /** @return list<array<string, string>> */
    private static function identityColumns(): array
    {
        return [
            ['key' => 'customer_code', 'label' => 'sales::credit.code', 'type' => ReportColumn::TEXT, 'width' => '7rem'],
            [
                'key' => 'customer_name',
                'label' => 'sales::credit.customer',
                'type' => ReportColumn::DOCUMENT,
                'source_type' => 'party_type_literal',
                'source_id' => 'id',
            ],
        ];
    }
}
