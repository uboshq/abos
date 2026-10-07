<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Dashboard;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\Quotation;
use App\Modules\Purchase\Models\Rfq;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ক্রয়ের ড্যাশবোর্ডের বাকি নকশা — মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬।
 *
 * ⓘ সইয়ের অপেক্ষায় বিল, এ মাসের ফেরত, ক্রয়ের ফানেল, ক্রয়াদেশের অবস্থা, দামের ওঠানামা।
 * ⓘ সবই কেবল দেখার — কিছু লেখে না। মডেলের কোয়েরি মডেলের নিজের পাহারায় (কোম্পানি + হেডারে বাছা শাখা,
 * [[ScopedToUserBranch]]); কাঁচা কোয়েরিতে কোম্পানি আর শাখা হাতে বসানো ([[DataScope::inView()]])।
 * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2); সুইচ বন্ধে প্রতিটা ফাঁকা তালিকা ফেরায়।
 */
final class PurchaseCharts
{
    /**
     * ⭐ এ মাসের ফেরত আর সইয়ের অপেক্ষার বিল — দুইটা সংখ্যা।
     *
     * ⓘ ফেরত: এ মাসের নিশ্চিত (বা বন্ধ) ফেরতের কাগজ — কয়টা, আর মোট কত টাকার। খসড়া আর বাতিল বাদ।
     * ⓘ সইয়ের অপেক্ষা: অনুমোদনের খোলা অনুরোধ, ক্রয় বিলের — নিচের তালিকার একই ছাঁকনি, তাই দুইটা কখনো দুই কথা বলে না।
     *
     * @return list<Stat>
     */
    public static function stats(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        [$from, $to] = self::month();

        $returns = PurchaseReturn::query()->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$from, $to])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as amount')
            ->toBase()->first();

        $waiting = self::awaiting()->count();

        return [
            new Stat(
                label: __('purchase::dashboard.returns_this_month'),
                value: Money::format((string) ($returns->amount ?? '0')),
                hint: __('purchase::dashboard.returns_hint', ['count' => (int) ($returns->n ?? 0)]),
                href: route('purchase.return.index'),
                tone: Stat::WARN,
                permission: 'purchase.return.view',
            ),
            new Stat(
                label: __('purchase::dashboard.awaiting_approval'),
                value: (string) $waiting,
                hint: __('purchase::dashboard.awaiting_approval_hint'),
                href: route('purchase.bill.index'),
                tone: $waiting > 0 ? Stat::WARN : Stat::GOOD,
                permission: 'purchase.bill.view',
            ),
        ];
    }

    /**
     * ⭐ ফানেল, ক্রয়াদেশের অবস্থা — দুইটা চার্ট, প্রথম চার্টের পরে (প্রথমটা হোমে যায়, তাই সেটা নড়ে না)।
     *
     * @return list<Breakdown>
     */
    public static function panels(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        return array_values(array_filter([self::funnel(), self::orderStatus()]));
    }

    /**
     * ⭐ সইয়ের অপেক্ষায় বিল, আর দামের ওঠানামা — দুইটা তালিকা।
     *
     * ⛔ দুইটাতেই বিলের অঙ্ক বা দর — কেবল `purchase.bill.view` যাঁর আছে; চাবি না থাকলে তালিকাটাই নেই।
     *
     * @return list<Listing>
     */
    public static function listings(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('purchase.bill.view')) {
            return [];
        }

        return [self::awaitingList(), self::priceChanges()];
    }

    /**
     * ⓘ ক্রয় বিলের খোলা অনুমোদনের অনুরোধ — মডিউল `purchase`, কাগজ ক্রয় বিল।
     *
     * ⚠️ অনুমোদনের সারিতে শাখা নেই; শাখা আসে বিল থেকে — `PurchaseBill::query()` কোম্পানি আর হেডারে বাছা শাখা দুইটাই মানে,
     * তাই অন্য শাখার বিলের অনুরোধ এখানে আসে না (মালিক, ৫ অক্টোবর ২০২৬: ড্যাশবোর্ড বাছা শাখা মানছিল না)।
     *
     * @return Builder<Approval>
     */
    private static function awaiting(): Builder
    {
        return Approval::query()->pending()
            ->where('module', 'purchase')
            // ⓘ ইঞ্জিন যা লেখে হুবহু তাই — কাগজের ক্লাসের নাম ([[ApprovalEngine]])
            ->where('approvable_type', PurchaseBill::class)
            ->whereIn('approvable_id', PurchaseBill::query()->select('pur_bills.id'));
    }

    private static function awaitingList(): Listing
    {
        return new Listing(
            label: __('purchase::dashboard.awaiting_approval'),
            columns: [
                ['key' => 'no', 'label' => __('purchase::field.document_no'),
                    'render' => fn (Approval $a) => $a->approvable?->document_no ?? '—'],
                ['key' => 'party', 'label' => __('purchase::field.supplier'),
                    'render' => fn (Approval $a) => $a->approvable?->supplier?->name() ?? '—'],
                ['key' => 'since', 'label' => __('purchase::dashboard.waiting_since'), 'width' => '9rem',
                    'render' => fn (Approval $a) => $a->requested_at?->format('d M Y') ?? '—'],
                ['key' => 'amount', 'label' => __('purchase::field.total'), 'width' => '9rem',
                    'render' => fn (Approval $a) => Money::format($a->approvable?->total ?? '0')],
            ],
            rows: self::awaiting()->with('approvable.supplier')->orderBy('requested_at')->orderBy('id')->limit(8)->get(),
            empty: __('purchase::dashboard.nothing_awaiting'),
            href: route('purchase.bill.index'),
        );
    }

    /**
     * ⭐ ক্রয়ের ফানেল — চাহিদা → দর চাওয়া → দরপত্র → ক্রয়াদেশ → মাল গ্রহণ → বিল → পরিশোধ, এ মাসে।
     *
     * ⓘ সংখ্যা কাগজের, টাকার নয় — কোন ধাপে এসে ক্রয় আটকে যাচ্ছে সেটাই প্রশ্ন (বিক্রয়ের ফানেলের মতোই, [[SalesCharts]])।
     * ⓘ প্রতিটা ধাপে কেবল পাকা কাগজ (নিশ্চিত বা বন্ধ) — খসড়া আর বাতিল বাদ। দরপত্রের তারিখ `quoted_on`, বাকিদের `trx_date`।
     * ⓘ সরাসরি ক্রয়ে মাল গ্রহণের কাগজ জন্মায় না (বিলই মাল আনে), তাই বিলের ধাপ মাল গ্রহণের চেয়ে বড় হতে পারে — সেটা ভুল নয়।
     */
    private static function funnel(): Breakdown
    {
        [$from, $to] = self::month();

        $count = fn (Builder $q, string $date = 'trx_date') => (string) $q->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween($date, [$from, $to])->count();

        return new Breakdown(
            label: __('purchase::dashboard.funnel'),
            parts: [
                ['label' => __('purchase::dashboard.funnel_requisitions'), 'value' => $count(PurchaseRequisition::query())],
                ['label' => __('purchase::dashboard.funnel_rfqs'), 'value' => $count(Rfq::query())],
                ['label' => __('purchase::dashboard.funnel_quotations'), 'value' => $count(Quotation::query(), 'quoted_on')],
                ['label' => __('purchase::dashboard.funnel_orders'), 'value' => $count(PurchaseOrder::query())],
                ['label' => __('purchase::dashboard.funnel_receipts'), 'value' => $count(PurchaseReceipt::query())],
                ['label' => __('purchase::dashboard.funnel_bills'), 'value' => $count(PurchaseBill::query())],
                ['label' => __('purchase::dashboard.funnel_payments'), 'value' => $count(Payment::query())],
            ],
            hint: __('purchase::dashboard.funnel_hint'),
            chart: 'funnel',
            range: DateRange::label($from, $to),
        );
    }

    /**
     * ⭐ ক্রয়াদেশের অবস্থা — এ বছরের আদেশ, খসড়া / নিশ্চিত (মাল আসার অপেক্ষায়) / বন্ধ / বাতিল, ডোনাটে।
     *
     * ⓘ ক্রম অবস্থার নিজের ক্রম ([[DocumentStatus::ALL]]); যে অবস্থায় একটাও নেই সেটা বাদ। এ বছরে একটাও আদেশ না থাকলে চার্টই নেই।
     */
    private static function orderStatus(): ?Breakdown
    {
        $from = Carbon::today()->startOfYear()->toDateString();
        $to = Carbon::today()->toDateString();

        $counts = PurchaseOrder::query()->whereBetween('trx_date', [$from, $to])
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')
            ->toBase()->pluck('n', 'status');

        $parts = [];

        foreach (DocumentStatus::ALL as $status) {
            if ((int) ($counts[$status] ?? 0) > 0) {
                $parts[] = ['label' => DocumentStatus::label($status), 'value' => (string) (int) $counts[$status]];
            }
        }

        if ($parts === []) {
            return null;
        }

        return new Breakdown(
            label: __('purchase::dashboard.order_status', ['year' => Carbon::today()->year]),
            parts: $parts,
            hint: __('purchase::dashboard.order_status_hint'),
            chart: 'donut',
            range: DateRange::label($from, $to),
        );
    }

    /**
     * ⭐ দামের ওঠানামা — যে পাঁচটা পণ্যের শেষ বিলের দর আগের বিলের দর থেকে সবচেয়ে বেশি সরেছে (শতাংশে, ওঠা বা নামা)।
     *
     * ⓘ দর বিলের সারির `rate` — পণ্যের নিজের এককে, তাই কার্টনে না পিসে লেখা হয়েছিল তাতে তুলনা বদলায় না।
     * ⓘ "শেষ" = সবচেয়ে নতুন তারিখের নিশ্চিত বিল (একই দিনে হলে পরের নম্বর); "আগের" = তার আগের **অন্য** একটা বিল।
     * ⓘ গত বারো মাসের বিল দেখা হয় — এক বছরের পুরনো দরের সাথে তুলনা দাম ঠিক করতে কাজে আসে না, আর পড়াটাও সীমায় থাকে।
     * ⓘ কাঁচা কোয়েরি — কোম্পানি আর হেডারে বাছা শাখা হাতে বসানো; শতাংশ bcmath-এ, ভাসমান সংখ্যা নয়।
     */
    private static function priceChanges(): Listing
    {
        $today = Carbon::today()->toDateString();

        $query = DB::table('pur_bill_lines')
            ->join('pur_bills', 'pur_bills.id', '=', 'pur_bill_lines.purchase_bill_id')
            ->where('pur_bills.company_id', CompanyContext::id())
            ->whereIn('pur_bills.status', DocumentStatus::POSTED)
            ->whereNull('pur_bills.deleted_at')
            ->whereBetween('pur_bills.trx_date', [Carbon::today()->subYear()->toDateString(), $today])
            ->where('pur_bill_lines.rate', '>', 0)
            ->select('pur_bill_lines.product_id', 'pur_bill_lines.rate', 'pur_bills.id as bill_id')
            ->orderBy('pur_bill_lines.product_id')
            ->orderByDesc('pur_bills.trx_date')
            ->orderByDesc('pur_bills.id')
            ->orderBy('pur_bill_lines.id');

        app(DataScope::class)->inView($query, 'pur_bills.branch_id');

        $latest = [];
        $previous = [];

        foreach ($query->cursor() as $line) {
            $product = (int) $line->product_id;

            if (! isset($latest[$product])) {
                $latest[$product] = ['rate' => (string) $line->rate, 'bill' => (int) $line->bill_id];
            } elseif (! isset($previous[$product]) && (int) $line->bill_id !== $latest[$product]['bill']) {
                $previous[$product] = (string) $line->rate;
            }
        }

        $rows = [];

        foreach ($previous as $product => $last) {
            $current = $latest[$product]['rate'];

            if (bccomp($current, $last, 4) === 0) {
                continue;
            }

            $rows[] = [
                'product_id' => $product,
                'last' => $last,
                'current' => $current,
                'change' => bcdiv(bcmul(bcsub($current, $last, 4), '100', 4), $last, 4),
            ];
        }

        $abs = fn (string $v) => ltrim($v, '-');
        usort($rows, fn (array $a, array $b) => bccomp($abs($b['change']), $abs($a['change']), 4) ?: $a['product_id'] <=> $b['product_id']);
        $rows = array_slice($rows, 0, 5);

        $names = Product::query()->whereKey(array_column($rows, 'product_id'))->get()->keyBy('id');

        return new Listing(
            label: __('purchase::dashboard.price_changes'),
            columns: [
                ['key' => 'product', 'label' => __('purchase::field.product'),
                    'render' => fn (array $r) => $names->get($r['product_id'])?->name() ?? '—'],
                ['key' => 'last', 'label' => __('purchase::dashboard.last_rate'), 'width' => '8rem',
                    'render' => fn (array $r) => Money::format($r['last'])],
                ['key' => 'current', 'label' => __('purchase::dashboard.current_rate'), 'width' => '8rem',
                    'render' => fn (array $r) => Money::format($r['current'])],
                ['key' => 'change', 'label' => __('purchase::dashboard.rate_change'), 'width' => '7rem',
                    'render' => fn (array $r) => (bccomp($r['change'], '0', 4) > 0 ? '+' : '').Money::format($r['change']).'%'],
            ],
            rows: collect($rows),
            empty: __('purchase::dashboard.no_price_changes'),
            href: route('purchase.bill.index'),
        );
    }

    /** @return array{0: string, 1: string} এ মাসের প্রথম দিন থেকে আজ — অ্যাপের ঘড়িতে, ডাটাবেজের নয় */
    private static function month(): array
    {
        return [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()];
    }
}
