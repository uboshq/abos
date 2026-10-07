<?php

declare(strict_types=1);

namespace App\Modules\Customer\Dashboard;

use App\Core\Contracts\CustomerSalesFilters;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Support\Carbon;

/**
 * গ্রাহকের ড্যাশবোর্ডের নতুন সংখ্যা আর চার্ট — মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬ (config abos.dashboards_v2)।
 *
 * ── ⚠️ বিক্রির অঙ্ক কোথা থেকে ───────────────────────────────────────────
 * গ্রাহক মডিউল বিক্রয়কে চেনে না (`depends_on`-এ নেই) — ⛔ তাই এখানে `SalesInvoice` বা `sal_invoices` নেই।
 * বিক্রি, সবচেয়ে বড় ক্রেতা আর মেয়াদ পেরোনো বিল আসে কোরের চুক্তি [[CustomerSalesFilters]] দিয়ে, যেটা বিক্রয়
 * বাস্তবায়ন করে; বিক্রয় বন্ধ থাকলে [[NoCustomerSalesFilters]] `null` বলে, আর তখন এই সংখ্যাগুলোই নেই —
 * মিথ্যা শূন্য নয়। ⓘ বকেয়া গ্রাহকের নিজের খাতা থেকে ([[Customer::scopeWithOutstandingInView()]]), বিক্রয় থেকে নয়।
 *
 * ⓘ আলাদা ফাইল, যাতে [[CustomerDashboard]]-এর পুরনো অংশ ছোঁয়া না লাগে — সুইচ বন্ধে পর্দাটা হুবহু আগের মতো।
 */
final class CustomerTradeCharts
{
    /** সবচেয়ে বড় ক্রেতার তালিকায় কয়জন — মালিকের নকশায় পাঁচ */
    public const TOP_BUYERS = 5;

    /** মেয়াদ পেরোনো চার্টে কয়জন — বাকিটা আদায়ের সূচিতে */
    private const OVERDUE_BARS = 8;

    /**
     * ⭐ এ মাসের বিক্রয়, গড় বিলের অঙ্ক, মেয়াদ পেরোনো বাকি আর কতজনের — চারটা কার্ড, এক সারি।
     *
     * @return list<Stat>
     */
    public static function stats(): array
    {
        if (! self::on()) {
            return [];
        }

        $sales = app(CustomerSalesFilters::class);
        $bills = $sales->billsBetween(self::monthStart(), self::today());
        $overdue = $sales->overdueByCustomer(self::today());

        if ($bills === null || $overdue === null) {
            return [];
        }

        // ⓘ গড় = মোট ÷ বিলের সংখ্যা; বিল না থাকলে শূন্য (ভাগ শূন্যে নয়)
        $average = $bills['count'] > 0 ? bcdiv($bills['total'], (string) $bills['count'], 4) : '0';
        $overdueTotal = array_reduce($overdue, fn (string $sum, array $row) => bcadd($sum, $row['amount'], 4), '0');

        return [
            new Stat(
                label: __('customer::dashboard.sales_month'),
                value: Money::format($bills['total']),
                hint: __('customer::dashboard.sales_month_hint', ['count' => $bills['count']]),
                href: route('customer.index'),
                tone: Stat::GOOD,
            ),
            new Stat(
                label: __('customer::dashboard.average_order'),
                value: Money::format($average),
                hint: __('customer::dashboard.average_order_hint'),
                href: route('customer.index'),
            ),
            // ⚠️ মেয়াদ পেরোনো বাকি বাড়া খারাপ খবর — `BAD`, যাতে তীরের রং উল্টো না পড়ে
            new Stat(
                label: __('customer::dashboard.overdue'),
                value: Money::format($overdueTotal),
                hint: __('customer::dashboard.overdue_hint'),
                href: route('customer.report.show', ['slug' => 'ageing']),
                tone: Stat::BAD,
            ),
            new Stat(
                label: __('customer::dashboard.overdue_customers'),
                value: (string) count($overdue),
                hint: __('customer::dashboard.overdue_customers_hint'),
                href: route('customer.report.show', ['slug' => 'ageing']),
                tone: Stat::WARN,
            ),
        ];
    }

    /**
     * ⭐ চার্ট — গ্রাহকের ধরন (ডোনাট) আর মেয়াদ পেরোনো বাকি, গ্রাহক ধরে (আড়াআড়ি দণ্ড)।
     *
     * @return list<Breakdown>
     */
    public static function panels(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        return array_values(array_filter([self::segments(), self::overdueBars()]));
    }

    /**
     * ⭐ এ মাসের সবচেয়ে বড় পাঁচ ক্রেতা, আর তাঁদের এখনকার বকেয়া।
     *
     * @return list<Listing>
     */
    public static function listings(): array
    {
        if (! self::on()) {
            return [];
        }

        $top = app(CustomerSalesFilters::class)->topBuyers(self::monthStart(), self::today(), self::TOP_BUYERS);

        if ($top === null) {
            return [];
        }

        $sold = array_column($top, 'total', 'customer_id');

        /*
         * ⓘ বকেয়া গ্রাহকের খাতা থেকে, হেডারে বাছা শাখায় — গ্রাহক তালিকার "বকেয়া" ঘরের একই হিসাব
         * ([[Customer::scopeWithOutstandingInView()]])। ⓘ ক্রম বিক্রির — চুক্তি যে ক্রমে দিয়েছে।
         */
        $rows = Customer::query()
            ->whereIn('customers.id', array_keys($sold))
            ->withOutstandingInView()
            ->get()
            ->each(fn (Customer $c) => $c->setAttribute('sold_this_month', $sold[$c->id] ?? '0'))
            ->sortBy(fn (Customer $c) => array_search($c->id, array_keys($sold), true))
            ->values();

        return [new Listing(
            label: __('customer::dashboard.top_buyers'),
            columns: [
                ['key' => 'code', 'label' => __('customer::field.code'), 'width' => '7rem',
                    'render' => fn (Customer $c) => $c->code],
                ['key' => 'name', 'label' => __('customer::field.name'),
                    'render' => fn (Customer $c) => $c->name()],
                ['key' => 'sold', 'label' => __('customer::dashboard.top_buyers_sold'), 'width' => '9rem',
                    'render' => fn (Customer $c) => Money::format($c->getAttribute('sold_this_month'))],
                ['key' => 'due', 'label' => __('customer::dashboard.top_buyers_due'), 'width' => '9rem',
                    'render' => fn (Customer $c) => Money::format($c->getAttribute('outstanding_in_view') ?? '0')],
            ],
            rows: $rows,
            empty: __('customer::dashboard.top_buyers_none'),
            href: route('customer.index'),
        )];
    }

    /**
     * ⭐ সচল গ্রাহক, ধরন ধরে (`customers.party_type_id` → ধরনের নাম) — পুরোটার ভাগ, তাই ডোনাট।
     * ⓘ উপরের "সচল" কার্ডের একই ভিত (দেখার শাখার সচল গ্রাহক) — ভাগগুলোর যোগফল ঐ কার্ডের সংখ্যা।
     * ⓘ ধরন বাছা নেই এমন গ্রাহকও একটা ভাগ — বাদ দিলে যোগফল কার্ডের সাথে মিলত না। ছয়ের বেশি ধরন হলে দণ্ডে আঁকা হয়।
     */
    private static function segments(): ?Breakdown
    {
        $counts = Customer::query()->inViewedBranch()
            ->where('customers.is_active', true)
            ->selectRaw('customers.party_type_id, COUNT(*) as n')
            ->groupBy('customers.party_type_id')
            ->orderByRaw('COUNT(*) desc')
            ->orderBy('customers.party_type_id')
            ->toBase()
            ->get();

        if ($counts->isEmpty()) {
            return null;
        }

        $names = PartyType::query()->whereIn('id', $counts->pluck('party_type_id')->filter())->get()
            ->mapWithKeys(fn (PartyType $t) => [$t->id => $t->name()]);

        return new Breakdown(
            label: __('customer::dashboard.segments'),
            parts: $counts->map(fn ($r): array => [
                'label' => (string) ($r->party_type_id === null
                    ? __('customer::dashboard.segments_none')
                    : ($names[$r->party_type_id] ?? __('customer::dashboard.segments_none'))),
                'value' => (string) (int) $r->n,
            ])->values()->all(),
            hint: __('customer::dashboard.segments_hint'),
            chart: 'donut',
        );
    }

    /** ⭐ মেয়াদ পেরোনো বাকি, গ্রাহক ধরে — র‍্যাঙ্কিং, তাই আড়াআড়ি দণ্ড; বড়টা উপরে */
    private static function overdueBars(): ?Breakdown
    {
        if (! self::on()) {
            return null;
        }

        $overdue = app(CustomerSalesFilters::class)->overdueByCustomer(self::today());

        if ($overdue === null || $overdue === []) {
            return null;
        }

        $overdue = array_slice($overdue, 0, self::OVERDUE_BARS);
        $names = Customer::query()->whereIn('id', array_column($overdue, 'customer_id'))->get()
            ->mapWithKeys(fn (Customer $c) => [$c->id => $c->name()]);

        return new Breakdown(
            label: __('customer::dashboard.overdue_by_customer'),
            parts: array_map(fn (array $row): array => [
                'label' => (string) ($names[$row['customer_id']] ?? '—'),
                'value' => Money::format($row['amount']),
            ], $overdue),
            hint: __('customer::dashboard.overdue_by_customer_hint'),
            chart: 'hbars',
        );
    }

    /** নতুন ড্যাশবোর্ড চালু, আর টাকার অঙ্ক দেখার চাবি আছে — বয়সের চার্টের একই চাবি (`customer.report`) */
    private static function on(): bool
    {
        return (bool) config('abos.dashboards_v2') && (bool) auth()->user()?->can('customer.report');
    }

    private static function monthStart(): string
    {
        return Carbon::today()->startOfMonth()->toDateString();
    }

    private static function today(): string
    {
        return Carbon::today()->toDateString();
    }
}
