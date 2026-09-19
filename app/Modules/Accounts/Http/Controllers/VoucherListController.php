<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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

        $query = $this->scoped(Voucher::query(), $tab)
            ->search($request->query('q'))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('trx_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('trx_date', '<=', $d));

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
        $counts = [];

        foreach (self::TABS as $each) {
            $counts[$each] = $this->scoped(Voucher::query(), $each)
                ->when($request->query('from'), fn ($q, $d) => $q->whereDate('trx_date', '>=', $d))
                ->when($request->query('to'), fn ($q, $d) => $q->whereDate('trx_date', '<=', $d))
                ->count();
        }

        return view('accounts::voucher.list', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
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
}
