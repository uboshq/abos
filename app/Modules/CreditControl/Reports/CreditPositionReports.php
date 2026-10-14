<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বাকি নিয়ন্ত্রণের তালিকা — ড্যাশবোর্ডের প্রতিটা সংখ্যার নিচের তালিকা (বাকি নিয়ন্ত্রণ ধাপ ১.২, ১১ অক্টোবর ২০২৬)।
 *
 *   · বাকির অবস্থা — প্রতিটা গ্রাহক: সীমা, বকেয়া, আটকে থাকা, ক্লিয়ার না হওয়া চেক, ব্যবহৃত, অবশিষ্ট, অবস্থা।
 *   · সীমা পার — যাঁদের ব্যবহৃত সীমার বেশি। · মেয়াদ পেরোনো বাকিতে বন্ধ — দেয়াল যাঁদের নতুন বাকি আটকায়।
 *   · মেয়াদ পেরোনো বিল — বিল ধরে, কত দিন পেরিয়েছে। · সীমা বাড়ানোর অনুরোধ — সই না পড়া।
 *
 * ⛔ এক হিসাব, দুই নয়: প্রতিটা অঙ্ক [[CreditExposure::positionsFor()]] আর [[CreditExposure::openBills()]] থেকে — দেয়াল আর
 * তালিকা কখনো দুই কথা বলে না। ⓘ অঙ্কগুলো PHP-তে গোনা, তারপর কোয়েরিতে গ্রাহক-ধরে বসানো ([[cases()]]), যাতে ইঞ্জিন পাতা-ভাগ,
 * মোট আর ফাইল আগের মতোই করে ([[CreditControlReports]]-এর ছাঁচ)।
 *
 * ⓘ দেয়াল: কোম্পানি, শাখা (গ্রাহকের নিজের শাখা — হেডারের বাছাসহ), আর বিক্রয়কর্মী কেবল নিজের ডিলার (⛔১৬)।
 */
final class CreditPositionReports
{
    public const POSITIONS = 'credit_control.positions';

    public const OVER_LIMIT = 'credit_control.over_limit';

    public const OVERDUE_STOPPED = 'credit_control.overdue_stopped';

    public const OVERDUE_BILLS = 'credit_control.overdue_bills';

    public const PENDING_REQUESTS = 'credit_control.pending_requests';

    /** ⭐ বাকি বন্ধ গ্রাহক — দেয়ালের নিজের অবস্থা থেকে (ধাপ ৩.২) */
    public const BLOCKED = 'credit_control.blocked';

    /** ড্যাশবোর্ডের বয়সের ধাপ — মালিকের তালিকা: মেয়াদ আসেনি, ১–৭, ৮–১৫, ১৬–৩০, ৩১–৬০, ৬১–৯০, ৯০+ */
    public const BUCKETS = ['not_due' => [null, 0], 'd1_7' => [1, 7], 'd8_15' => [8, 15], 'd16_30' => [16, 30],
        'd31_60' => [31, 60], 'd61_90' => [61, 90], 'd90_plus' => [91, null]];

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::positions(self::POSITIONS, 'credit_control::report.positions_title', null));
        $engine->register(self::positions(self::OVER_LIMIT, 'credit_control::report.over_limit_title', 'over_limit'));
        $engine->register(self::positions(self::OVERDUE_STOPPED, 'credit_control::report.overdue_stopped_title', 'overdue'));
        $engine->register(self::positions(self::BLOCKED, 'credit_control::report.blocked_title', 'blocked'));
        $engine->register(self::overdueBills());
        $engine->register(self::pendingRequests());
    }

    /** ধাপের নাম — দিন থেকে */
    public static function bucketOf(int $daysOverdue): string
    {
        foreach (self::BUCKETS as $key => [$from, $to]) {
            if (($from === null || $daysOverdue >= $from) && ($to === null || $daysOverdue <= $to)) {
                return $key;
            }
        }

        return 'd90_plus';
    }

    /**
     * ⛔ সক্রিয় গ্রাহক, আর নিষ্ক্রিয় হলেও যাঁর খাতায় এখনো জের আছে (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
     * [[AnInactiveDebtorLeftTheCreditReportsTest]])।
     *
     * ⚠️ বকেয়া রেখে নিষ্ক্রিয় করা গ্রাহক — ঠিক যাঁর টাকা আদায় সবচেয়ে জরুরি — মেয়াদোত্তীর্ণ, ঝুঁকি আর ড্যাশবোর্ডের মোট থেকে
     * নীরবে পড়ে যেতেন। ⓘ জের শূন্য হলে আর আসেন না।
     */
    public static function activeOrStillOwing(\Illuminate\Database\Eloquent\Builder $query, int $companyId): void
    {
        $query->where(fn ($q) => $q->where('customers.is_active', true)
            ->orWhereIn('customers.id', \Illuminate\Support\Facades\DB::table('ledger_entries')
                ->select('party_id')
                ->where('company_id', $companyId)
                ->where('party_type', Customer::drillSourceType())
                ->groupBy('party_id')
                ->havingRaw('SUM(debit) - SUM(credit) <> 0')));
    }

    /**
     * দেয়ালের ভিতরের সক্রিয় গ্রাহক, বকেয়াসহ — তালিকা আর ড্যাশবোর্ড একই দরজা দিয়ে ঢোকে।
     *
     * @param  array<string, mixed>  $f
     * @return array<int, Customer>
     */
    public static function customers(array $f): array
    {
        return Customer::query()
            ->where('customers.company_id', $f['company_id'])
            ->tap(fn ($q) => self::activeOrStillOwing($q, (int) $f['company_id']))
            ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'customers.id')) // ⛔১৬ — বিক্রয়কর্মী কেবল নিজের ডিলার
            ->when(! empty($f['salesman_id']), fn ($q) => $q->whereIn('customers.id', DealerBinding::query()
                ->current()->where('user_id', (int) $f['salesman_id'])->select('customer_id')))
            ->withOutstanding()
            ->get()
            ->keyBy(fn (Customer $c) => (int) $c->id)
            ->all();
    }

    private static function positions(string $key, string $title, ?string $state): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            permission: 'credit_control.view',
            title: $title,
            filters: ['branch', 'salesman_id'],
            query: function (array $f) use ($state): Builder {
                $credit = app(CreditExposure::class);
                $customers = self::customers($f);
                $positions = $credit->positionsFor($customers);

                if ($state !== null) {
                    $positions = array_filter($positions, fn (array $p) => $p['state'] === $state);
                }

                $overdue = [];
                foreach ($credit->openBills(array_intersect_key($customers, $positions)) as $id => $bills) {
                    foreach ($bills as $bill) {
                        if ($bill['days_overdue'] > 0) {
                            $overdue[$id] = bcadd($overdue[$id] ?? '0', $bill['unpaid'], 4);
                        }
                    }
                }

                $pick = fn (string $field) => self::cases(array_map(fn (array $p) => $p[$field], $positions));
                $states = array_map(fn (array $p) => __('credit_control::state.'.$p['state']), $positions);

                return self::base($f, array_keys($positions))
                    ->selectRaw($pick('limit').' as credit_limit')
                    ->selectRaw($pick('due').' as due')
                    ->selectRaw($pick('held').' as held')
                    ->selectRaw($pick('uncleared').' as uncleared')
                    ->selectRaw($pick('exposure').' as exposure')
                    ->selectRaw($pick('available').' as available')
                    ->selectRaw('CASE WHEN '.$pick('limit').' > 0 THEN ROUND('.$pick('exposure').' * 100 / '.$pick('limit').', 1) END as used_percent')
                    ->selectRaw(self::cases($overdue).' as overdue')
                    ->selectRaw(self::texts($states).' as state')
                    ->orderByRaw($pick('exposure').' DESC')
                    ->orderBy('customers.code');
            },
            columns: [
                ...self::identityColumns(),
                ['key' => 'credit_limit', 'label' => 'credit_control::report.limit', 'type' => ReportColumn::MONEY],
                ['key' => 'due', 'label' => 'credit_control::report.due', 'type' => ReportColumn::MONEY],
                ['key' => 'held', 'label' => 'credit_control::report.held', 'type' => ReportColumn::MONEY],
                ['key' => 'uncleared', 'label' => 'credit_control::report.uncleared', 'type' => ReportColumn::MONEY],
                ['key' => 'exposure', 'label' => 'credit_control::report.exposure', 'type' => ReportColumn::MONEY],
                ['key' => 'available', 'label' => 'credit_control::report.available', 'type' => ReportColumn::MONEY],
                ['key' => 'used_percent', 'label' => 'credit_control::report.used', 'type' => ReportColumn::PERCENT, 'total' => false],
                ['key' => 'overdue', 'label' => 'credit_control::report.overdue', 'type' => ReportColumn::MONEY],
                ['key' => 'state', 'label' => 'credit_control::report.state'],
            ],
        );
    }

    /** মেয়াদ পেরোনো বিল — বিল ধরে, দেয়ালের হিসাবের বাকি ([[CreditExposure::openBills()]]) */
    private static function overdueBills(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::OVERDUE_BILLS,
            permission: 'credit_control.view',
            title: 'credit_control::report.overdue_bills_title',
            filters: ['branch', 'salesman_id'],
            query: function (array $f): Builder {
                $unpaid = [];
                $days = [];

                foreach (app(CreditExposure::class)->openBills(self::customers($f)) as $bills) {
                    foreach ($bills as $bill) {
                        if ($bill['days_overdue'] > 0) {
                            $unpaid[$bill['id']] = $bill['unpaid'];
                            $days[$bill['id']] = (string) $bill['days_overdue'];
                        }
                    }
                }

                $bucket = array_map(fn (string $d) => __('credit_control::report.bucket_'.self::bucketOf((int) $d)), $days);

                return DB::table('sal_invoices as i')
                    ->join('customers', 'customers.id', '=', 'i.customer_id')
                    ->where('i.company_id', $f['company_id'])
                    ->where('customers.company_id', $f['company_id'])
                    ->whereIn('i.id', $unpaid === [] ? [0] : array_keys($unpaid))
                    // ⓘ দেয়াল আর ছাঁকনি কোয়েরিতেও — গ্রাহকের তালিকা PHP-তে ছাঁকে, কিন্তু ছাঁকনি যেন কোয়েরিতেও বসে (ADrawnFilterBox)
                    ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'customers.id'))
                    ->tap(self::salesmanWall($f))
                    ->select(['i.id as invoice_id', 'i.document_no', 'customers.id', 'customers.code as customer_code'])
                    ->selectRaw("'sales_invoice' as invoice_type_literal")
                    ->selectRaw(self::name().' as customer_name')
                    ->selectRaw("'".self::PROFILE."' as party_type_literal")
                    ->selectRaw('COALESCE(i.due_on, i.trx_date) as due_on')
                    ->selectRaw(self::cases($days, 'i.id').' as days_overdue')
                    ->selectRaw(self::texts($bucket, 'i.id').' as bucket')
                    ->selectRaw(self::cases($unpaid, 'i.id').' as unpaid')
                    ->orderByRaw(self::cases($days, 'i.id').' DESC')
                    ->orderBy('i.id');
            },
            columns: [
                ['key' => 'document_no', 'label' => 'credit_control::report.bill', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'invoice_type_literal', 'source_id' => 'invoice_id'],
                ...self::identityColumns(),
                ['key' => 'due_on', 'label' => 'credit_control::report.due_on', 'type' => ReportColumn::DATE],
                ['key' => 'days_overdue', 'label' => 'credit_control::report.days_overdue', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'bucket', 'label' => 'credit_control::report.bucket'],
                ['key' => 'unpaid', 'label' => 'credit_control::report.unpaid', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** সই না পড়া সীমা বাড়ানোর অনুরোধ — সই পড়ার আগে পুরনো সীমাই চলে */
    private static function pendingRequests(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::PENDING_REQUESTS,
            permission: 'credit_control.view',
            title: 'credit_control::report.pending_requests_title',
            filters: ['branch', 'salesman_id'],
            // ⓘ দুই পথের অনুরোধ — গ্রাহকের ফর্মে সরাসরি বাড়ানো (কাগজ গ্রাহক নিজে), আর বাকির পাতার অনুরোধ (কাগজ
            // [[CustomerCreditRequest]], ধাপ ২.৫); গ্রাহক আর চাওয়া সীমা যার যার ঘর থেকে
            query: fn (array $f): Builder => DB::table('approvals as a')
                ->leftJoin('customer_credit_requests as r', fn ($j) => $j->on('r.id', '=', 'a.approvable_id')
                    ->where('a.approvable_type', CustomerCreditRequest::class))
                ->join('customers', 'customers.id', '=', DB::raw('COALESCE(r.customer_id, a.approvable_id)'))
                ->leftJoin('users as ru', 'ru.id', '=', 'a.requested_by')
                ->where('a.company_id', $f['company_id'])
                ->where('customers.company_id', $f['company_id'])
                ->whereIn('a.approvable_type', [(new Customer)->getMorphClass(), CustomerCreditRequest::class])
                ->where('a.module', 'customer')
                ->where('a.action', 'credit_limit')
                ->where('a.status', Approval::PENDING)
                ->whereIn('customers.id', array_keys(self::customers($f)) ?: [0])
                ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'customers.id'))
                ->select(['a.id as approval_id', 'customers.id', 'customers.code as customer_code'])
                ->selectRaw(self::name().' as customer_name')
                ->selectRaw("'".self::PROFILE."' as party_type_literal")
                ->selectRaw('DATE(a.requested_at) as requested_on, COALESCE(customers.credit_limit, 0) as current_limit, COALESCE(r.requested_limit, a.amount) as asked_limit')
                ->selectRaw('ru.name as requested_by, a.requested_reason as reason')
                ->orderBy('a.requested_at')
                ->orderBy('a.id'),
            columns: [
                ['key' => 'requested_on', 'label' => 'credit_control::report.requested_on', 'type' => ReportColumn::DATE],
                ...self::identityColumns(),
                ['key' => 'current_limit', 'label' => 'credit_control::report.limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'asked_limit', 'label' => 'credit_control::report.asked_limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'requested_by', 'label' => 'credit_control::report.requested_by'],
                ['key' => 'reason', 'label' => 'credit_control::report.reason'],
            ],
        );
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** গ্রাহকের নামে চাপলে তাঁর বাকির পাতা ([[CreditProfile]]) */
    public const PROFILE = 'credit_profile';

    /**
     * @param  array<string, mixed>  $f
     * @param  list<int>  $ids
     */
    public static function base(array $f, array $ids): Builder
    {
        return DB::table('customers')
            ->where('customers.company_id', $f['company_id'])
            ->whereNull('customers.deleted_at')
            ->whereIn('customers.id', $ids === [] ? [0] : $ids)
            ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'customers.id'))
            ->tap(self::salesmanWall($f))
            ->select(['customers.id', 'customers.code as customer_code'])
            ->selectRaw(self::name().' as customer_name')
            ->selectRaw("'".self::PROFILE."' as party_type_literal");
    }

    /**
     * ⭐ বিক্রয়কর্মী ছাঁকনি, কোয়েরিতে — আজ যাঁর সাথে বাঁধা ([[DealerBinding::current()]]), গ্রাহকের তালিকার একই নিয়ম ([[customers()]])।
     *
     * ⓘ তালিকা PHP-তে আগেই ছাঁকা, তবু কোয়েরিতেও বসে: নইলে খালি ফলের দিনে ছাঁকনিটা কোয়েরির চেহারা বদলাত না, আর মরা ছাঁকনি
     * আর জীবিত ছাঁকনি আলাদা করা যেত না ([[ADrawnFilterBoxThatChangesNothingTest]])।
     *
     * @param  array<string, mixed>  $f
     */
    public static function salesmanWall(array $f): \Closure
    {
        return fn ($q) => $q->when(! empty($f['salesman_id']), fn ($q) => $q->whereIn('customers.id', DealerBinding::query()
            ->current()->where('user_id', (int) $f['salesman_id'])->select('customer_id')));
    }

    /**
     * PHP-তে গোনা অঙ্ক, কোয়েরির একটা ঘর হয়ে — `CASE id WHEN … END`। ⓘ চাবি পূর্ণসংখ্যা, মান bcmath-এর সংখ্যা।
     *
     * @param  array<int, string>  $values
     */
    public static function cases(array $values, string $column = 'customers.id'): string
    {
        $cases = '';

        foreach ($values as $id => $value) {
            $cases .= ' WHEN '.(int) $id.' THEN '.bcadd((string) $value, '0', 4);
        }

        return $cases === '' ? '(0 + 0)' : "(CASE {$column}{$cases} ELSE 0 END)";
    }

    /**
     * একই, লেখার জন্য — অনুবাদ করা লেখা PDO দিয়ে উদ্ধৃত।
     *
     * @param  array<int, string>  $values
     */
    public static function texts(array $values, string $column = 'customers.id'): string
    {
        $pdo = DB::getPdo();
        $cases = '';

        foreach ($values as $id => $value) {
            $cases .= ' WHEN '.(int) $id.' THEN '.$pdo->quote((string) $value);
        }

        return $cases === '' ? "''" : "(CASE {$column}{$cases} ELSE '' END)";
    }

    public static function name(): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(customers.name_bn, ''), customers.name_en)"
            : "COALESCE(NULLIF(customers.name_en, ''), customers.name_bn)";
    }

    /** @return list<array<string, string>> */
    public static function identityColumns(): array
    {
        return [
            ['key' => 'customer_code', 'label' => 'credit_control::report.code', 'type' => ReportColumn::TEXT, 'width' => '7rem'],
            [
                'key' => 'customer_name',
                'label' => 'credit_control::report.customer',
                'type' => ReportColumn::DOCUMENT,
                'source_type' => 'party_type_literal',
                'source_id' => 'id',
            ],
        ];
    }
}
