<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * সব ভাউচার এক জায়গায়, ট্যাবে ভাগ করা।
 *
 * ── ⭐ মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"Master-এর পাশে 'Voucher List' নামে মেনু বানাও, তাতে ট্যাব করো —
 * Receipt Voucher, Sales Added Deposit, Payment Voucher, Exp. Voucher,
 * Journal, Contra, others voucher।"*
 *
 * ⓘ মেনুর পাঁচটা ভাউচারের সারি ([[VoucherController::index()]]) আগের
 * মতোই থাকে — সেখানে নতুন ভাউচার লেখা হয়। এই পাতা **খোঁজার** জায়গা:
 * সব ধরন এক পর্দায়, এক ক্লিকে বদলানো যায়।
 *
 * ── ⚠️ সাতটা ট্যাব, আর প্রতিটা ভাউচার ঠিক একটাতে ─────────────────────
 * ⛔ একটা ভাউচার দুই ট্যাবে দেখালে দুই ট্যাবের যোগফল মিলিয়ে কেউ দ্বিগুণ
 * টাকা গুনতেন। তাই ভাগটা এমন যে কোনো সারি দুইবার আসে না:
 *
 *   · **Sales Added Deposit** — বিক্রয় বিলের বিপরীতে নেওয়া রসিদ
 *     (`against_type = sales_invoice`)। ⓘ কাউন্টারের ডিপোজিট নতুন নকশায়
 *     ঠিক এভাবেই বসবে — বিলের সাথে বাঁধা রসিদ হয়ে।
 *   · **Others** — অন্য পর্দা যেগুলো নিজে বানায়: Finance-এর খাতা, টাকা
 *     গোনার সমন্বয়, POS … (`against_type` আছে, বিক্রয় বিল নয়)। ⓘ কোথা
 *     থেকে এল সেটা আলাদা কলামে।
 *   · **বাকি পাঁচটা** — হাতে লেখা ভাউচার, ধরন ধরে (`against_type` খালি)।
 */
class VoucherListController extends Controller implements HasMiddleware
{
    use SortsLists;

    public const SALES_DEPOSIT = 'sales_deposit';

    public const OTHERS = 'others';

    /**
     * বিক্রয় বিলের নাম, যেমন `against_type`-এ লেখা থাকে।
     *
     * ⛔ এখানে হাতে লেখা, `SalesInvoice::drillSourceType()` ডাকা নয় — হিসাব
     * মডিউল কারও উপর নির্ভর করে না (`depends_on` ফাঁকা), আর [[BoundariesTest]]
     * বিক্রয়ের মডেল ডাকা আটকায়।
     *
     * ⚠️ তাই মিলটা পাহারায়: [[TheVoucherListHasSevenTabsTest]] দুইটা নাম মিলিয়ে
     * দেখে। ⛔ নাহলে বিক্রয় বিলের নাম বদলালে এই ট্যাব নীরবে খালি হয়ে যেত।
     */
    public const SALES_INVOICE_SOURCE = 'sales_invoice';

    /** ⓘ একই কারণে হাতে লেখা, আর একই পাহারা — উপরের মন্তব্য দেখুন। */
    public const PURCHASE_BILL_SOURCE = 'purchase_bill';

    /*
     * ⭐ ক্রয় আর বিক্রয় — মালিকের সম্মতি, ১৯ সেপ্টেম্বর ২০২৬।
     *
     * ── ⓘ এগুলো ভাউচার নয়, আর সেটাই ইচ্ছাকৃত ────────────────────────
     * মালিক জিজ্ঞেস করেছিলেন *"ক্রয় ভাউচার, বিক্রয় ভাউচার নেই?"*। ⚠️ এই
     * ব্যবস্থায় কেনাবেচা খাতায় ওঠে নিজের কাগজে — ক্রয় বিল আর বিক্রয় বিল
     * নিজেই দাখিলা বসায়। ⛔ আলাদা ভাউচার বানালে একই কেনাবেচা খাতায়
     * **দুইবার** উঠত।
     *
     * ⭐ তাই এই দুইটা ট্যাব কেবল **দেখায়** — খাতায় বসা বিলগুলো, খাতা
     * (`ledger_entries`) থেকে পড়ে। ⓘ খাতা সবার সাধারণ, তাই হিসাব মডিউলকে
     * ক্রয় বা বিক্রয়ের উপর নির্ভর করতে হয় না; বিলটা খোলার লিংক দেয়
     * কোরের `<x-ui.drill>`।
     */
    public const PURCHASE = 'purchase';

    public const SALES = 'sales';

    /**
     * কোন ট্যাব খাতার কোন উৎস পড়ে।
     *
     * @var array<string, string>
     */
    public const DOCUMENT_TABS = [
        self::PURCHASE => self::PURCHASE_BILL_SOURCE,
        self::SALES => self::SALES_INVOICE_SOURCE,
    ];

    /**
     * মালিকের ক্রমেই — ট্যাবের সারিটা ঠিক এভাবে পড়তে হবে।
     *
     * @var list<string>
     */
    public const TABS = [
        Voucher::RECEIPT,
        self::SALES_DEPOSIT,
        Voucher::PAYMENT,
        Voucher::EXPENSE,
        Voucher::JOURNAL,
        Voucher::CONTRA,
        self::OTHERS,
        self::PURCHASE,
        self::SALES,
    ];

    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        // ⓘ মেনুর পাঁচটা ভাউচারের সারি যে চাবি চায়, এটাও তাই
        return [new Middleware('can:accounts.report')];
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true)
            ? (string) $request->query('tab')
            : Voucher::RECEIPT;

        if (isset(self::DOCUMENT_TABS[$tab])) {
            return $this->documents($request, $tab);
        }

        [$from, $to] = $this->range($request);

        $query = $this->scoped(Voucher::query(), $tab)
            ->search($request->query('q'))
            ->where('trx_date', '>=', $from)
            ->where('trx_date', '<=', $to);

        $sort = $this->applySort($query, $request, [
            'latest' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'amount' => fn ($q) => $q->orderByDesc('amount'),
            'document_no' => fn ($q) => $q->orderBy('document_no'),
        ]);

        /*
         * ⓘ প্রতিটা ট্যাবের পাশে সংখ্যা — খুলে না দেখেই জানা যায় কোথায়
         * কী আছে। ⚠️ তারিখের ছাঁকনি সংখ্যাতেও খাটে, নাহলে ট্যাব বলত
         * "১২০" আর খুললে দেখাত "৩"।
         */
        return view('accounts::voucher.list', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $this->counts($request),
            'vouchers' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'sort' => $sort,
            'sortOptions' => [
                'latest' => __('accounts::sort.latest'),
                'oldest' => __('accounts::sort.oldest'),
                'amount' => __('accounts::sort.amount'),
                'document_no' => __('core.print.document_no'),
            ],
        ]);
    }

    /**
     * খাতায় বসা ক্রয় বা বিক্রয় বিল — কেবল দেখার জন্য।
     */
    private function documents(Request $request, string $tab): View
    {
        $term = trim((string) $request->query('q', ''));

        $query = $this->posted(self::DOCUMENT_TABS[$tab], $request)
            ->when($term !== '', fn ($q) => $q->having('document_no', 'like', '%'.$term.'%'));

        $sort = $this->applySort($query, $request, [
            'latest' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('source_id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('source_id'),
            'amount' => fn ($q) => $q->orderByDesc('amount'),
            'document_no' => fn ($q) => $q->orderBy('document_no'),
        ]);

        return view('accounts::voucher.list', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $this->counts($request),
            'vouchers' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'sort' => $sort,
            'sortOptions' => [
                'latest' => __('accounts::sort.latest'),
                'oldest' => __('accounts::sort.oldest'),
                'amount' => __('accounts::sort.amount'),
                'document_no' => __('core.print.document_no'),
            ],
        ]);
    }

    /**
     * একটা উৎসের খাতায় বসা কাগজগুলো, প্রতি কাগজে এক সারি।
     *
     * ── ⚠️ অঙ্কটা নিট, আসল দাখিলার যোগ নয় ───────────────────────────
     * নিশ্চিত বিল পরে বদলালে [[PostingEngine::reverse()]] আগের দাখিলা উল্টে
     * দেয় (`<উৎস>:reversal` নামে), আর নতুন দাখিলা আবার আসল নামেই বসে।
     * ⛔ কেবল আসল নামের ডেবিট যোগ করলে বদলানো বিল **দ্বিগুণ** দেখাত।
     *
     * ⓘ তাই অঙ্ক = আসল নামের ডেবিট − উল্টো নামের ডেবিট (উল্টোটায় ডেবিট-
     * ক্রেডিট অদলবদল, তাই ওর ডেবিট = আগের দাখিলার মোট)। ⚠️ বাতিল বিলে নিট
     * শূন্য, আর সেগুলো তালিকায় আসে না — খাতায় ওদের আর কোনো ভার নেই।
     */
    private function posted(string $source, Request $request): Builder
    {
        [$from, $to] = $this->range($request);

        $reversal = $source.':reversal';
        $net = 'SUM(CASE WHEN source_type = ? THEN debit ELSE 0 END)'
            .' - SUM(CASE WHEN source_type = ? THEN debit ELSE 0 END)';
        $firstDate = 'MIN(CASE WHEN source_type = ? THEN trx_date END)';

        return LedgerEntry::query()
            ->whereIn('source_type', [$source, $reversal])
            ->groupBy('source_id')
            ->select('source_id')
            ->selectRaw('? as source_type', [$source])
            ->selectRaw($firstDate.' as trx_date', [$source])
            ->selectRaw('MAX(CASE WHEN source_type = ? THEN document_no END) as document_no', [$source])
            ->selectRaw('MAX(CASE WHEN source_type = ? THEN narration END) as narration', [$source])
            ->selectRaw($net.' as amount', [$source, $reversal])
            ->havingRaw($net.' > 0', [$source, $reversal])
            ->havingRaw($firstDate.' >= ?', [$source, $from])
            ->havingRaw($firstDate.' <= ?', [$source, $to]);
    }

    /**
     * প্রতিটা ট্যাবের পাশের সংখ্যা — তারিখের ছাঁকনি সহ।
     *
     * @return array<string, int>
     */
    private function counts(Request $request): array
    {
        $counts = [];

        foreach (self::TABS as $each) {
            $counts[$each] = isset(self::DOCUMENT_TABS[$each])
                ? DB::query()->fromSub($this->posted(self::DOCUMENT_TABS[$each], $request)->toBase(), 'd')->count()
                : $this->scoped(Voucher::query(), $each)
                    ->when($request->query('from'), fn ($q, $d) => $q->whereDate('trx_date', '>=', $d))
                    ->when($request->query('to'), fn ($q, $d) => $q->whereDate('trx_date', '<=', $d))
                    ->count();
        }

        return $counts;
    }

    /**
     * একটা ট্যাবে কোন ভাউচারগুলো পড়ে।
     *
     * ⚠️ তিনটা শাখা মিলে সব ভাউচার ঢাকে, আর কোনোটা দুইবার নয় — উপরের
     * মন্তব্য দেখুন। ⓘ বিক্রয় বিলের নামটা কেন এখানে হাতে লেখা, সেটা
     * [[SALES_INVOICE_SOURCE]]-এ।
     */
    private function scoped(Builder $query, string $tab): Builder
    {
        $invoice = self::SALES_INVOICE_SOURCE;

        return match ($tab) {
            self::SALES_DEPOSIT => $query
                ->where('type', Voucher::RECEIPT)
                ->where('against_type', $invoice),

            /*
             * ⚠️ "বিক্রয় বিল নয়" নয়, "বিক্রয় বিলের রসিদ নয়" — প্রথম লেখায়
             * বিক্রয় বিলের বিপরীতে লেখা **পরিশোধ** (ফেরত টাকা) কোনো ট্যাবেই
             * পড়ত না। ⓘ এখন তিন শাখা মিলে সব সারি ঢাকে, আর পাহারা সেটা গুনে দেখে।
             */
            self::OTHERS => $query
                ->whereNotNull('against_type')
                ->where(fn ($q) => $q
                    ->where('against_type', '<>', $invoice)
                    ->orWhere('type', '<>', Voucher::RECEIPT)),

            default => $query
                ->where('type', $tab)
                ->whereNull('against_type'),
        };
    }

    /**
     * কোন তারিখ থেকে কোন তারিখ — না বললে চলতি মাস।
     *
     * ── ⛔ আগে কোনো ডিফল্ট ছিল না, ২১ সেপ্টেম্বর ২০২৬ পর্যন্ত ──────────
     * শর্ত দুইটা `when()`-এর ভিতরে ছিল, অর্থাৎ ঠিকানায় তারিখ না দিলে
     * **পুরো টেবিলটা** আসত। ⓘ আজ ভাউচার গোটা কয়েক, তাই কিছুই দেখা
     * যায় না; বছর দুয়েক পরে পাতাটা খুলতেই সময় লাগবে, আর তখন কারণটা
     * খুঁজে বের করা কঠিন হবে।
     *
     * ⚠️ ইঞ্জিনের নিজের নিয়মও তাই ([[ReportEngine]]: প্রতিটা তালিকা
     * নিজের তারিখ-সীমা বহন করে) — কেবল এই পর্দাটা সেটা মানত না।
     *
     * ⭐ চলতি মাস বাছা হয়েছে কারণ ভাউচারের পাতায় মানুষ প্রায় সবসময়
     * সাম্প্রতিকটাই খোঁজেন, আর সীমাটা ঠিকানায় লেখা থাকে বলে অন্য মাস
     * চাওয়া এক ক্লিক দূরে।
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            filled($from) ? (string) $from : Carbon::today()->startOfMonth()->toDateString(),
            filled($to) ? (string) $to : Carbon::today()->endOfMonth()->toDateString(),
        ];
    }
}
