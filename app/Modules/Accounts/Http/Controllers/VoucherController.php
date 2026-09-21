<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\FormChoices;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounts\Http\Requests\VoucherRequest;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Accounts\Models\MoneyCategory;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ভাউচারের স্ক্রিন — পাঁচ ধরন, দুই আকারের ফর্ম।
 *
 * ধরনটা URL-এ থাকে (/accounts/vouchers/receipt/create), তাই মেনুর
 * পাঁচটা সারি পাঁচটা আলাদা পর্দায় নিয়ে যায় — অথচ কোড এক। DMS-এ
 * পাঁচটা আলাদা কন্ট্রোলার ছিল, আর সেখানেই কন্ট্রার দিক উল্টে গিয়েছিল।
 *
 * resourcePermissions() ব্যবহার করা হয়নি: রুটগুলো resource ছকে বসে না
 * (ধরন URL-এ), আর পোস্ট ও বাতিল আলাদা অনুমতি চায়।
 */
class VoucherController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly MenuBuilder $menu,
        private readonly VoucherApproval $approvals,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.report', only: ['index', 'show']),
            new Middleware('can:accounts.voucher.create', only: ['create', 'store']),
            new Middleware('can:accounts.voucher.update', only: ['edit', 'update', 'post']),
            new Middleware('can:accounts.voucher.delete', only: ['cancel']),
        ];
    }

    /**
     * এক ধরনের ভাউচারের তালিকা।
     *
     * ধরনটা রুট থেকে আসে, ফিল্টার থেকে নয়: "আদায়" মেনু থেকে এসে
     * ফিল্টার বদলে পরিশোধ দেখতে পাওয়াটা বিভ্রান্তিকর হত, আর ছাপার
     * শিরোনামও তখন ভুল হত।
     */
    public function index(Request $request, string $type): View
    {
        $type = $this->assertType($type);

        /*
         * ⭐ অনুমোদনের অপেক্ষায় ঝুলে আছে — ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ এটা এখানে এল কেন ─────────────────────────
         * মালিকের সিদ্ধান্ত: *"খরচ ভাউচার finance থেকে তুলে দাও …
         * যা রাখতে হয় accounts-এ রাখো"*। অর্থের খরচের পর্দায় এই
         * একটা জিনিসই অন্য কোথাও ছিল না।
         *
         * ── ⛔ অনুমোদন সেন্টার এর উত্তর নয় ───────────────
         * ইনবক্স কেবল **যিনি সিদ্ধান্ত দিতে পারেন** তাঁকে দেখায়।
         * যিনি কাগজটা লিখেছেন অথচ অনুমোদনকারী নন, তিনি ওখানে
         * খালি পাতা পান — নিজের ঝুলে থাকা খরচটা আর কোথাও দেখেন না।
         *
         * ⚠️ আর ওগুলো খাতায় বসেও নেই — অর্থাৎ মাসের যোগফলেও নেই,
         * চোখেও নেই। ⭐ তাই সংখ্যাটা তালিকার মাথায়, আর চাপ দিলে
         * কেবল সেগুলোই দেখা যায়।
         *
         * ⓘ গোনাটা পাঁচ ধরনের ভাউচারেই চলে, কেবল খরচে নয় — পাঁচটাই
         * `module.php`-তে অনুমোদনের কাজ হিসেবে ঘোষিত।
         */
        $awaitingIds = Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('module', VoucherApproval::MODULE)
            ->where('action', $type)
            ->pending()
            ->pluck('approvable_id');

        $query = Voucher::query()
            ->ofType($type)
            ->search($request->query('q'))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('trx_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('trx_date', '<=', $d))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            /*
             * ⛔ খালি তালিকায় `whereIn` দিলে শূন্য সারি আসে, আর সেটাই
             * ঠিক: ঝুলে থাকা কিছু না থাকলে ছাঁকনিটাও খালি দেখাবে।
             */
            ->when($request->boolean('awaiting'), fn ($q) => $q->whereIn('id', $awaitingIds));

        $sort = $this->applySort($query, $request, $this->sorts());

        $vouchers = $query->paginate(50)->withQueryString();

        /*
         * ⭐ পক্ষ · কী বাবদ · কোথায় · মাধ্যম — ১৯ সেপ্টেম্বর ২০২৬, মালিকের
         * "koro"। ⓘ দাখিলার লাইন আর পক্ষের নাম একবারে তোলা হয়, সারি ধরে নয়।
         */
        $vouchers->getCollection()->load('lines.account');

        $partyNames = app(PartyRegistry::class)->labelsOf(
            $vouchers->getCollection()
                ->filter(fn (Voucher $v) => $v->party_type !== null && $v->party_id !== null)
                ->map(fn (Voucher $v) => [(string) $v->party_type, (int) $v->party_id]),
        );

        return view('accounts::voucher.index', [
            'partyNames' => $partyNames,
            'menu' => $this->menu->forUser($request->user()),
            'type' => $type,
            'vouchers' => $vouchers,
            'q' => $request->query('q'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
            'awaitingCount' => $awaitingIds->count(),
            'awaitingIds' => $awaitingIds->map(fn ($id) => (int) $id)->all(),
            'awaiting' => $request->boolean('awaiting'),
        ]);
    }

    /**
     * সাজানোর নিয়ম — নতুন আগে, কারণ ভাউচারের তালিকা আজকের কাজ দেখতে
     * খোলা হয়। টাকার অঙ্ক দিয়ে সাজানো লাগে অন্য প্রশ্নে: "সবচেয়ে বড়
     * খরচটা কী ছিল"।
     *
     * @return array<string, \Closure>
     */
    private function sorts(): array
    {
        return [
            'latest' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'amount' => fn ($q) => $q->orderByDesc('total'),
            'document_no' => fn ($q) => $q->orderBy('document_no'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'latest' => __('accounts::sort.latest'),
            'oldest' => __('accounts::sort.oldest'),
            'amount' => __('accounts::sort.amount'),
            'document_no' => __('core.print.document_no'),
        ];
    }

    public function create(Request $request, string $type): View
    {
        $type = $this->assertType($type);

        return view($type === Voucher::JOURNAL ? 'accounts::voucher.journal-form' : 'accounts::voucher.simple-form', [
            'menu' => $this->menu->forUser($request->user()),
            'type' => $type,
            'voucher' => new Voucher([
                'type' => $type,
                'trx_date' => now(),
                ...$this->prefill($request),
            ]),
            ...$this->formOptions($type),
        ]);
    }

    /**
     * একজন পক্ষের কাছে এখন কত পাওনা — ফর্মের "Collectable" ঘরের উত্তর।
     *
     * ── ⭐ কেন ঘরটা ব্যবহারকারী লেখেন না ────────────────────────────
     * মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬: সংখ্যাটা নিজে থেকে আসবে।
     * হাতে লিখতে দিলে সেটা খতিয়ানের একটা দ্বিতীয় উৎস হত, আর দুই উৎস
     * মানে একদিন দুই উত্তর — এই রিপো ওটা একবার দেখেছে।
     *
     * ⚠️ সংখ্যাটা **এখনকার**, ভাউচারের তারিখের নয়। আদায়ের সময় প্রশ্নটা
     * "আজ তাঁর কাছে কত পাওনা", "গত মাসে কত ছিল" নয়। ঘরটার লেবেলও তাই
     * বলে, যাতে কেউ ওটাকে ঐতিহাসিক জের ভেবে না বসেন।
     *
     * ⓘ অচেনা পক্ষের ধরন এলে ৪২২ নয়, **শূন্য** — কারণ এটা একটা সহায়ক
     * ঘর, আর একটা সহায়ক ঘরের জন্য ফর্ম ভাঙা উচিত নয়। তবে চুপচাপ শূন্যও
     * নয়: `known` মিথ্যা হলে পর্দা "—" দেখায়, "০.০০" নয়।
     */
    public function due(Request $request): JsonResponse
    {
        /*
         * ⚠️ চাবিটা `middleware()`-এ নয়, এখানে — আর কারণটা এই মডিউলেই
         * আগে লেখা আছে ([[module.php]]-র মেনু-অংশে): **ক্যাশিয়ারের
         * `accounts.voucher.create` আছে কিন্তু `accounts.report` নেই**।
         *
         * ⛔ তাই `accounts.report` দিয়ে আটকালে ঠিক যিনি ঘরটা রোজ দেখেন
         * তিনিই "—" দেখতেন, আর কেউ বুঝত না কেন।
         *
         * ⓘ আর `middleware()` দিয়ে **দুইটার যেকোনো একটা** বলা যায় না —
         * একই মেথড দুইটা `only:`-তে থাকলে দুইটাই লাগে (AND)। এখানে
         * দরকার OR, তাই শর্তটা হাতে।
         */
        abort_unless(
            $request->user()?->canAny(['accounts.voucher.create', 'accounts.voucher.update']) ?? false,
            403,
        );

        $type = (string) $request->query('party_type', '');
        $id = (int) $request->query('party_id', 0);

        if ($id <= 0 || ! app(PartyRegistry::class)->knows($type)) {
            return response()->json(['known' => false, 'amount' => '0.0000']);
        }

        /*
         * ⚠️ পক্ষটা সত্যিই এই কোম্পানির কি না — নাহলে অন্য কোম্পানির
         * আইডি পাঠিয়ে তাদের বকেয়ার অঙ্ক পড়ে ফেলা যেত।
         */
        if (! app(PartyRegistry::class)->exists($type, $id)) {
            return response()->json(['known' => false, 'amount' => '0.0000']);
        }

        return response()->json([
            'known' => true,
            'amount' => app(AccountsFacts::class)->dueFrom($type, $id),
            'bills' => $this->openBillsOf($type, $id),
        ]);
    }

    /**
     * এই পক্ষের যে বিলগুলো এখনো পুরো শোধ হয়নি।
     *
     * ── ⭐ কেন মোট বকেয়া যথেষ্ট নয় ──────────────────────────────────
     * [[AccountsFacts::dueFrom]] খতিয়ান ধরে **একটা সংখ্যা** দেয় — "তিনি
     * মোট কত দেবেন"। ⓘ কিন্তু টাকা এলে প্রশ্নটা আলাদা: **কোন বিলের
     * বিপরীতে?**
     *
     * ⚠️ সেটা না জানলে পুরনো বিলটা চিরকাল খোলা থাকত আর নতুনটা শোধ
     * দেখাত, অথচ টাকাটা একই। ⓘ বয়স ধরে বকেয়ার তালিকা (aging) তখন
     * মিথ্যা বলত, আর "কার টাকা কতদিন আটকে" প্রশ্নের উত্তরটাই ভুল হত।
     *
     * ── ⓘ কেন কেবল গ্রাহক ───────────────────────────────────────────
     * বিলভিত্তিক নিষ্পত্তি এই মুহূর্তে বিক্রয়ের দিকেই আছে
     * (`sal_invoices`)। ⚠️ সরবরাহকারীর দিকে একই জিনিস লাগবে, কিন্তু
     * সেটা `pur_bills` ধরে, আর সেই পথটা এখনো লেখা হয়নি — তাই খালি
     * তালিকা ফেরে, ভুল তালিকা নয়।
     *
     * @return list<array<string, mixed>>
     */
    private function openBillsOf(string $type, int $id): array
    {
        if ($type !== 'customer') {
            return [];
        }

        /*
         * ⓘ `paid` ধরা হয় ঐ ইনভয়েসের বিপরীতে বসা রসিদগুলোর যোগফল
         * থেকে (`against_type` / `against_id`) — আলাদা কোনো "শোধ হয়েছে"
         * কলাম নেই, আর সেটাই ঠিক: দুই জায়গায় লিখলে একদিন দুইটা আলাদা
         * হয়ে যেত।
         */
        return DB::table('sal_invoices as i')
            ->where('i.company_id', CompanyContext::id())
            ->where('i.customer_id', $id)
            ->where('i.status', 'posted')
            ->whereNull('i.deleted_at')
            ->leftJoin('vouchers as v', function ($join): void {
                $join->on('v.against_id', '=', 'i.id')
                    ->where('v.against_type', '=', 'sales_invoice')
                    ->whereNull('v.deleted_at')
                    ->where('v.status', '=', 'posted');
            })
            ->groupBy('i.id', 'i.document_no', 'i.trx_date', 'i.due_on', 'i.total')
            ->havingRaw('i.total - COALESCE(SUM(v.amount), 0) > 0.0001')
            ->orderBy('i.trx_date')
            ->limit(50)
            ->get([
                'i.id',
                'i.document_no',
                'i.trx_date',
                'i.due_on',
                'i.total',
                DB::raw('COALESCE(SUM(v.amount), 0) as paid'),
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'no' => $row->document_no,
                'date' => $row->trx_date,

                /*
                 * বয়স — দিনে, আর সেটা এখানেই গোনা হয়।
                 *
                 * ⓘ ব্রাউজারে গুনলে ফোনের ঘড়ি ভুল থাকলে বয়সটাও ভুল
                 * হত, আর ব্যবহারকারী বুঝতেন না কেন।
                 */
                'age' => (int) now()->startOfDay()->diffInDays(Carbon::parse($row->trx_date)->startOfDay()),
                'outstanding' => bcsub((string) $row->total, (string) $row->paid, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * অন্য মডিউল থেকে আসা রসিদের আগাম-ভরা ঘরগুলো।
     *
     * ── ⭐ মালিকের স্থাপত্যগত সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬ ───────────
     * তিনি প্রশ্ন করেছিলেন: *"অর্থে মূলধন লিখে হিসাবে রিসিভ করলেই তো
     * সমাধান — এক জায়গায় হয়, এত কী করো?"*
     *
     * তাই কাজ ভাগ হলো: **অর্থ কেবল লেখে "কে কত দেবেন", টাকা গ্রহণ করে
     * একাই রসিদের পর্দা।** অর্থের তালিকার "টাকা এসেছে" বোতামটা তাই
     * টেবিলের ঘরে ফর্ম আঁকে না — সে এই পর্দাটাই আগে থেকে ভরা অবস্থায়
     * খোলে।
     *
     * ── ⚠️ কেন কেবল এই কয়টা ঘর, সব নয় ──────────────────────────────
     * ⛔ পুরো `$request->query()` ঢেলে দেওয়া যেত না: তাহলে যে কেউ
     * URL-এ `status=posted` বা `company_id=2` জুড়ে দিয়ে ফর্মটা এমন
     * অবস্থায় খুলতে পারতেন যা কোনো বোতাম কোনোদিন বানায় না। তালিকাটা
     * তাই **সাদা তালিকা**, আর ছোট।
     *
     * ⓘ কোনো ঘরই এখানে যাচাই হয় না, আর হওয়ার দরকারও নেই — এগুলো কেবল
     * ফর্মের প্রাথমিক চেহারা। আসল যাচাই জমা দেওয়ার সময়,
     * [[VoucherRequest]]-এ, যেখানে সবকিছু আবার নতুন করে দেখা হয়।
     *
     * @return array<string, mixed>
     */
    private function prefill(Request $request): array
    {
        $allowed = [
            'against_type', 'against_id',
            'party_type', 'party_id',
            'amount', 'money_category_id', 'money_subcategory_id',
            'narration',
        ];

        return collect($allowed)
            ->mapWithKeys(fn (string $key) => [$key => $request->query($key)])
            ->filter(fn ($value) => filled($value))
            ->all();
    }

    public function store(VoucherRequest $request, string $type): RedirectResponse
    {
        $type = $this->assertType($type);

        $data = $request->validated();
        $data['type'] = $type;

        /*
         * সেভ করলেই পোস্ট, দুই ধাপ নয়।
         *
         * "সেভ" তারপর "পোস্ট" দুইটা বোতাম রাখলে দিনের শেষে একগাদা খসড়া
         * পড়ে থাকত যেগুলো কোনো হিসাবে নেই, আর কেউ জানত না সেগুলো ভুলে
         * যাওয়া নাকি ইচ্ছাকৃত। খসড়া রাখার দরকার হলে "খসড়া রাখুন"
         * বোতামটা আলাদা করে চাপতে হয়।
         *
         * ---- কেন দুইটা এক লেনদেনে, ৩০ আগস্ট ২০২৬ ----
         * আগে `create()` নিজের লেনদেনে কমিট হত, তারপর `post()` চলত।
         * পোস্টিং আটকালে (ব্যাংকের লেনদেন নম্বর নেই) ব্যতিক্রমটা
         * ফর্মে ফিরত, কিন্তু **খসড়াটা রয়ে যেত**।
         *
         * ব্যবহারকারী একটা ভুল-বার্তা দেখতেন যা বলে কিছুই হয়নি, আর
         * তালিকায় পড়ে থাকত একটা ভাউচার। HP-র ভাষায়: "নিঃশব্দে Draft
         * সেভ হয়ে যায়"। বারবার চেষ্টা করলে একগাদা অসম্পূর্ণ খসড়া --
         * ঠিক যে জিনিসটা উপরের নিয়মটা এড়াতে চায়।
         *
         * এখন দুইটাই এক লেনদেনে: পোস্ট না হলে খসড়াও নেই, আর
         * বার্তাটা যা বলে বাস্তবেও তাই। "খসড়া রাখুন" চাপলে খসড়াই
         * থাকে -- ওটা তখন ইচ্ছাকৃত, আর ইচ্ছাকৃতটা লুকানো নয়।
         */
        [$voucher, $waiting] = DB::transaction(function () use ($request, $data, $type) {
            $voucher = $this->vouchers->create($data, $this->linesFrom($request, $type));

            /*
             * ⭐ কোন চালানের ঘাড়ে কতটা — খরচ ভাউচারের ট্যাগ।
             *
             * ⓘ একই লেনদেনে, ভাউচারের সাথেই। ⚠️ আলাদা করলে পোস্টিং
             * আটকালে ভাউচারটা ফিরে যেত কিন্তু ট্যাগগুলো পড়ে থাকত —
             * অনাথ সারি, যেগুলোর ভাউচারই নেই।
             */
            $this->vouchers->replaceBillShares($voucher, $data['bill_shares'] ?? [], $data['alloc_basis'] ?? 'qty');

            /*
             * সংযুক্তি — বিলের ছবি বা স্ক্যান।
             *
             * ⛔ ইঞ্জিনটা নিজের ব্যতিক্রম ছোড়ে (আকার, ধরন, ভাঙা ছবি),
             * আর সেটা এখানে ধরা হয় **না**: লেনদেনটা তখন ফিরে যায়, আর
             * ব্যবহারকারী কারণটা দেখেন। ⚠️ চুপচাপ গিলে ফেললে ভাউচারটা
             * সেভ হত, ছবিটা হত না, আর কেউ জানত না।
             */
            if ($request->hasFile('attachment')) {
                app(AttachmentEngine::class)->store(
                    $request->file('attachment'),
                    'accounts',
                    Voucher::class,
                    $voucher->id,
                );
            }

            if ($request->boolean('save_as_draft')) {
                return [$voucher, false];
            }

            /*
             * অনুমোদন লাগলে খসড়াই থাকে, আর অনুরোধটা এখানেই যায়।
             *
             * ---- কেন এখানেও, শুধু post() রুটে নয় (৩ সেপ্টেম্বর ২০২৬) ----
             * উপরের নিয়ম অনুযায়ী "সেভ করলেই পোস্ট" -- অর্থাৎ খরচ লেখার
             * **স্বাভাবিক পথটা এই লাইনটাই**, `post()` রুট নয় (ওটায়
             * যাওয়া হয় কেবল খসড়া পরে বসাতে)। এখানে শর্তটা না বসালে
             * অনুমোদনের ছক বসানো থাকা সত্ত্বেও রোজকার খরচগুলো নীরবে
             * সরাসরি খতিয়ানে বসে যেত, আর ছকটা কেবল একটা কম-ব্যবহৃত
             * দরজাতেই কাজ করত -- সবচেয়ে খারাপ ধরনের আধা-পাহারা।
             */
            if ($this->approvals->stopping($voucher) !== null) {
                return [$voucher, true];
            }

            $this->vouchers->post($voucher);

            return [$voucher, false];
        });

        return redirect()
            ->route('accounts.voucher.show', $voucher)
            ->with(...$this->savedOrWaiting($voucher, $waiting));
    }

    public function show(Request $request, Voucher $voucher): View
    {
        $voucher->load(['lines.account', 'creator', 'approver', 'canceller', 'branch']);

        return view('accounts::voucher.show', [
            'menu' => $this->menu->forUser($request->user()),
            'voucher' => $voucher,
            'party' => $this->resolveParty($voucher),
        ]);
    }

    public function edit(Request $request, Voucher $voucher): View
    {
        $this->assertEditable($voucher);

        $voucher->load('lines');

        return view($voucher->type === Voucher::JOURNAL
            ? 'accounts::voucher.journal-form'
            : 'accounts::voucher.simple-form', [
                'menu' => $this->menu->forUser($request->user()),
                'type' => $voucher->type,
                'voucher' => $voucher,
                ...$this->formOptions($voucher->type),
            ]);
    }

    public function update(VoucherRequest $request, Voucher $voucher): RedirectResponse
    {
        $this->assertEditable($voucher);

        $validated = $request->validated();

        $this->vouchers->update($voucher, $validated, $this->linesFrom($request, $voucher->type));

        /*
         * ⓘ সম্পাদনাতেও একই — নাহলে টিক তুলে নিলে সারিটা থেকে যেত,
         * আর ঐ মালের দামে একটা খরচ বসে থাকত যেটা কেউ আর চায় না।
         */
        $this->vouchers->replaceBillShares(
            $voucher,
            $validated['bill_shares'] ?? [],
            $validated['alloc_basis'] ?? 'qty',
        );

        if ($request->hasFile('attachment')) {
            app(AttachmentEngine::class)->store(
                $request->file('attachment'),
                'accounts',
                Voucher::class,
                $voucher->id,
            );
        }

        $waiting = false;

        if (! $request->boolean('save_as_draft')) {
            $fresh = $voucher->fresh();

            // সম্পাদনার পরেও একই শর্ত -- নাহলে একবার খসড়া রেখে তারপর
            // সম্পাদনা করে পোস্ট করলেই পাহারাটা এড়ানো যেত।
            if ($this->approvals->stopping($fresh) !== null) {
                $waiting = true;
            } else {
                $this->vouchers->post($fresh);
            }
        }

        return redirect()
            ->route('accounts.voucher.show', $voucher)
            ->with(...$this->savedOrWaiting($voucher, $waiting));
    }

    /**
     * খসড়াটা লেজারে বসানো।
     *
     * ব্যাংকের লেনদেন নম্বরটা এখানেই নেওয়া হয়, লেখার ফর্মে নয় — লেখার
     * সময় নম্বরটা এখনো তৈরিই হয়নি। যা আসেনি তা মুছে যায় না, তাই
     * `filled()` — খালি পাঠালে আগের নম্বরটা টিকে থাকে।
     */
    public function post(Request $request, Voucher $voucher): RedirectResponse
    {
        $validated = $request->validate([
            'instrument_no' => ['nullable', 'string', 'max:64'],
        ]);

        if (filled($validated['instrument_no'] ?? null)) {
            $voucher->forceFill(['instrument_no' => trim($validated['instrument_no'])])->save();
        }

        /*
         * অনুমোদন লাগে কি না — পোস্টের **আগে**, খতিয়ানে কিছু লেখার আগে।
         *
         * ── কেন এখানে, সার্ভিসের ভিতরে নয় ───────────────────────────
         * `VoucherService::post()` ডাকা হয় সিডার, ইমপোর্ট আর অন্য
         * সার্ভিস থেকেও — ওখানে বসালে ডেমো ডেটা বসানোই আটকে যেত, আর
         * ইমপোর্ট করা দুই হাজার সারি অনুমোদনের অপেক্ষায় ঝুলে থাকত।
         * অনুমোদন **মানুষের সিদ্ধান্তের** উপর বসে, যন্ত্রের উপর নয়,
         * আর মানুষ আসে এই দরজা দিয়ে।
         *
         * ⚠️ নিচের `post()` আর তার ক্রম অস্পৃশ্য — এটা কেবল একটা শর্ত
         * তার আগে, যা `null` হলে সবকিছু আজকের মতোই চলে।
         */
        $stopping = $this->approvals->stopping($voucher);

        if ($stopping !== null) {
            return back()->with('warning', $stopping->status === Approval::REJECTED
                ? __('accounts::message.voucher_approval_rejected', [
                    'no' => $voucher->document_no,
                    'reason' => (string) $stopping->decisions()->latest('id')->value('remarks'),
                ])
                : __('accounts::message.voucher_approval_pending', ['no' => $voucher->document_no]));
        }

        $this->vouchers->post($voucher);

        return back()->with('saved', __('accounts::message.voucher_posted', ['no' => $voucher->document_no]));
    }

    /** বাতিল — বিপরীত এন্ট্রি দিয়ে, মুছে নয় (নিয়ম ৫)। */
    public function cancel(Request $request, Voucher $voucher): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->vouchers->cancel($voucher, $validated['cancel_reason']);

        return back()->with('saved', __('accounts::message.voucher_cancelled', ['no' => $voucher->document_no]));
    }

    /**
     * বার্তাটা কী হবে — বসে গেছে, নাকি অনুমোদনের অপেক্ষায়।
     *
     * ── কেন `saved` নয়, আলাদা একটা চাবি ─────────────────────────────
     * "সংরক্ষিত হয়েছে" লিখে সবুজ দেখালে মানুষ ধরে নিতেন খরচটা খাতায়
     * বসে গেছে — অথচ সেটা কেবল একটা খসড়া, অনুমোদনের অপেক্ষায়। মাস
     * শেষে হিসাব না মিললে কেউ বুঝত না কেন। **যা হয়নি তা হয়েছে বলা
     * সবচেয়ে দামি মিথ্যা**, আর এখানে দামটা টাকার।
     *
     * @return array{0: string, 1: string}
     */
    private function savedOrWaiting(Voucher $voucher, bool $waiting): array
    {
        return $waiting
            ? ['warning', __('accounts::message.voucher_approval_pending', ['no' => $voucher->document_no])]
            : ['saved', __('accounts::message.voucher_saved', ['no' => $voucher->document_no])];
    }

    /**
     * সহজ ফর্মের দুইটা খাতকে দুইটা সারিতে বদলানো।
     *
     * @return list<array<string, mixed>>
     */
    private function linesFrom(VoucherRequest $request, string $type): array
    {
        if ($type === Voucher::JOURNAL) {
            return array_values((array) $request->input('lines', []));
        }

        return $this->vouchers->twoLineEntry(
            $type,
            (int) $request->input('from_account_id'),
            (int) $request->input('to_account_id'),
            (string) $request->input('amount'),
            $request->input('narration'),

            /*
             * ⓘ খালি ঘর মানে চার্জ নেই, `0` নয় — আর পার্থক্যটা কাজের:
             * `null` পেলে [[VoucherService::twoLineEntry()]] আগের মতো
             * দুইটা সারিই বানায়, তাই চার্জহীন লক্ষ লক্ষ ভাউচারের পথ
             * এক চুলও বদলায় না।
             */
            ($request->input('charge_amount') ?? '') !== ''
                ? (string) $request->input('charge_amount')
                : null,
        );
    }

    /**
     * ফর্মে কোন খাতগুলো বাছা যাবে।
     *
     * প্রতিটা ধরনের জন্য আলাদা, আর সেটাই ভুল বাছা ঠেকানোর সবচেয়ে ভালো
     * উপায়: খরচ ভাউচারে গ্রাহকের খাত তালিকাতেই না থাকলে কেউ সেটা
     * বেছে ফেলতে পারে না।
     *
     * @return array<string, mixed>
     */
    private function formOptions(string $type): array
    {
        $money = Account::query()->money()->postable()->active()->orderBy('code')->get();
        $all = Account::query()->postable()->active()->orderBy('code')->get();

        /*
         * ⭐ নগদ কেবল নিজের ক্যাশবাক্সে — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ মালিকের নিয়ম ─────────────────────────────────────────
         * *"cash e sudu tar nijer cash accounts e taka nite parbe, tai
         * app er dorkar nai — din sese emnite tar kachtekei buje nibe"*।
         *
         * ⚠️ অর্থাৎ নগদে অনুমোদন তুলে দেওয়ার **শর্তটাই** হলো এই সীমা।
         * ⛔ শর্তটা না বসিয়ে অনুমোদন তুললে যে কেউ যেকোনো ক্যাশ খাতে
         * টাকা বসাতে পারত, আর দিন শেষে মেলানোর সময় সেটা ধরাই পড়ত না —
         * একটা জাল তুলে নেওয়া হত, বদলে কিছু বসত না।
         *
         * ── ⓘ ব্যাংক ও MFS অচ্ছুত নয় ──────────────────────────────
         * সীমাটা **কেবল নগদে**। ব্যাংক/MFS-এ টাকা প্রতিষ্ঠানের খাতেই
         * যায় আর সেখানে অনুমোদন থাকছে, তাই ওখানে তালিকা ছাঁকার কারণ নেই।
         *
         * ── ⚠️ যাঁর বাক্স নেই, তাঁর নগদের ঘরটাই খালি ─────────────────
         * ⓘ আর সেটাই ঠিক উত্তর: বাক্স ছাড়া মানুষের হাতে নগদ গেলে সেটা
         * কার হেফাজতে তার কোনো উত্তর থাকে না। ⛔ পর্দা তখন বলে দেয়
         * কেন ঘরটা খালি ([[accounts::message.no_till_of_your_own]])।
         */
        $myTills = CashTill::query()
            ->active()
            ->heldBy((int) auth()->id())
            ->pluck('account_id')
            ->all();

        $cashForMe = $money
            ->filter(fn (Account $a) => ! $a->isCash() || in_array((int) $a->id, $myTills, true))
            ->values();

        return [
            /*
             * ⓘ টাকার ঘরে যায় ছাঁকা তালিকাটা — নিজের বাক্স ছাড়া অন্য
             * কারও নগদ খাত এখানে আসে না।
             */
            'moneyAccounts' => $cashForMe,
            'hasOwnTill' => $myTills !== [],
            'allAccounts' => $all,

            /*
             * ⭐ টাকার মাধ্যমের ব্লকের দুইটা তালিকা — ১৪ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ এই দুইটা না পাঠালে ঘর দুইটা **নীরবে খালি** আসত: ড্রপডাউন
             * দেখা যেত, ভিতরে কিছু থাকত না, আর কেউ বলতে পারত না কেন।
             * ⓘ কম্পোনেন্টের ডিফল্ট `[]`, তাই কোনো ত্রুটিও আসত না।
             */

            /*
             * যাঁদের হাত দিয়ে টাকা যায় — গরমিল হলে এই নামটাই প্রথম প্রশ্ন।
             *
             * ⛔ প্রথমে এখানে `CompanyContext::companyId()` লেখা হয়েছিল, আর
             * সেই নামে কোনো পদ্ধতি **নেই** — আসল নাম `id()`। ⓘ নামটা যাচাই
             * না করে লেখা হয়েছিল, আর ফল ছিল পাতাটা খুললেই ৫০০।
             *
             * ⚠️ ভুলটা নীরব ছিল না — এটা জোরেই ভেঙেছে, আর সেটাই ভালো।
             * কিন্তু ভাঙাটা দেখেছেন মালিক, পরীক্ষা নয়, কারণ পরীক্ষাটা
             * তখনো চলছিল আর আমি ফল আসার আগেই তাঁকে দেখতে বলেছিলাম।
             * ⭐ শিক্ষা: "চালাচ্ছি" আর "চলেছে" এক নয়।
             */
            'carriers' => User::query()
                ->whereHas('companies', fn ($q) => $q->where('companies.id', CompanyContext::id()))
                ->orderBy('name')
                ->pluck('name', 'id'),

            /*
             * BEFTN · RTGS · NPSB — `mdm_transfer_modes` থেকে।
             *
             * ⚠️ টেবিলটা ২১ অক্টোবর থেকে বসানো, আর আজ পর্যন্ত **কোনো ফর্মে
             * ঘরটা ছিল না** — আজকের চেনা রোগ: নিয়ম লেখা, অথচ অপৌঁছানো।
             */

            /*
             * জাবেদার সারিতে বাছার মতো পক্ষগুলো।
             *
             * তালিকাটা আসে মডিউলের নিজের ঘোষণা থেকে, তাই এই ফাইলে
             * "গ্রাহক" বা "সরবরাহকারী" কথাটা লেখা নেই — নতুন কোনো
             * পক্ষ যোগ হলে এখানে হাত পড়বে না (সেকশন ১৯.৭)।
             */
            'parties' => app(PartyRegistry::class)->forPicker(),

            /*
             * খরচের কেন্দ্রগুলো — কোনোটা না থাকলে কলামটাই আসে না।
             *
             * যে ডিপো রুট ধরে খরচ দেখে না, তার প্রতিটা জাবেদায় একটা
             * খালি ঘর জায়গা নিত আর কিছুই বলত না।
             */
            'costCenters' => CostCenter::query()->active()->orderBy('code')->get(),

            /*
             * খরচের কেন্দ্র — খরচ ভাউচারের ড্রপডাউনের জন্য, চাবি-মান জোড়ায়।
             *
             * ⓘ উপরের `costCenters` জাবেদার সারির জন্য গোটা মডেল পাঠায়;
             * এখানে কেবল নাম দরকার। দুইটা আলাদা রাখা হয়েছে যাতে একটার
             * আকার বদলালে অন্যটা না ভাঙে।
             */
            'costCentres' => CostCenter::query()->active()->orderBy('code')
                ->get()->mapWithKeys(fn ($c) => [$c->id => $c->display_name ?? $c->name_bn ?? $c->name_en]),

            /*
             * ⭐ কাকে দেওয়া হলো — ধরনগুলো মাস্টার থেকে, হাতে লেখা নয়।
             *
             * ⓘ মালিক যে নামগুলো বললেন — vendor, transporter/carrier —
             * সেগুলো `mdm_party_types`-এ **আগে থেকেই বসানো**: সরবরাহকারী,
             * পরিবহনকারী, কুরিয়ার, হাম্মালি ঠিকাদার, সার্ভিস প্রোভাইডার,
             * প্রতিষ্ঠান। ⛔ তাই তালিকাটা কোডে লেখা হয়নি — লিখলে একদিন
             * মাস্টারে একটা ধরন যোগ হত আর এই পর্দায় আসত না।
             */

            /*
             * প্রতিটা ধরনের নিজের লোকজন — ধরন বাছলে নামের ঘরটা
             * তাঁদের দেখায়।
             *
             * ⚠️ আজকের ডেটায় কোনো সরবরাহকারীর ধরন বসানো নেই
             * (`party_type_id` সব খালি), তাই তালিকাগুলো আজ খালি আসবে
             * আর পর্দা নাম লেখার ঘরটাই দেখাবে। ⓘ সরবরাহকারীর ফর্মে
             * ধরন বসানো শুরু করলেই এটা নিজে থেকে কাজ করবে।
             */

            /*
             * ⭐ যে চালানগুলোয় এই খরচটা বসতে পারে — মালিকের ট্যাগের তালিকা।
             *
             * ── ⚠️ "আগে বসেছে" কলামটা কেন ─────────────────────────────
             * মালিকের কথা: *"একই পণ্যের বিলে দুইবার ভাড়া বসলে সমস্যা,
             * তাই যেগুলো পেন্ডিং তালিকা করে দিলেই ভালো"*।
             *
             * ⓘ তাই প্রতিটা চালানের পাশে **আগে কত খরচ বসেছে** তা দেখানো
             * হয়, আর ছাঁকনি দিয়ে কেবল খালিগুলো দেখা যায়। ⛔ দুইবার বসানো
             * **আটকানো হয় না** — মালিকের নির্দেশ: *"আটকে দেব না, দেখিয়ে
             * দেব"*। কখনো সত্যিই দুইবার ভাড়া লাগে (ফেরত, পুনঃপরিবহন)।
             *
             * ── ⓘ কেন কেবল সাম্প্রতিক ────────────────────────────────
             * বছরের সব চালান দেখালে তালিকাটা শ'য়ে শ'য়ে সারি হত, আর যে
             * ট্রাকটা আজ এসেছে সেটা খুঁজে পাওয়া যেত না। ৬০ দিনের সীমাটা
             * ব্যবসার ছন্দ থেকে: মাল আসার পর ভাড়ার বিল দিন তিনেকের
             * মধ্যেই আসে, আর দুই মাস যথেষ্ট বেশি।
             */
            /*
             * ⭐ অন্য মডিউলের ঘরগুলো — ২১ সেপ্টেম্বর ২০২৬, সীমারেখার নিরীক্ষা।
             *
             * ⚠️ আগে এখানে চারটা তালিকা হাতে লেখা ছিল: `TransferMode`,
             * `PartyType` (MasterData), `Supplier`, আর `PurchaseBill`
             * (Purchase)। ⛔ তিনটা মডিউলই accounts-এর উপর দাঁড়িয়ে, তাই
             * নির্ভরতাগুলো ঘোষণাও করা যেত না — চক্র হত।
             *
             * ⭐ এখন যার তালিকা, সে-ই দেয় ([[FormChoices]])। ⓘ মডিউল বন্ধ
             * থাকলে তার ঘরটা আসে না আর পর্দা ফাঁকা দেখায় — ভাঙে না,
             * তাই ভিউতে `?? []` ধরে নেওয়া হয়।
             */
            ...app(FormChoices::class)->for('accounts.voucher'),

            'expenseAccounts' => $all->where('type', Account::EXPENSE)->values(),

            /*
             * বাকিতে খরচের অন্য পাশ — প্রদেয় হিসাব।
             *
             * ⓘ আলাদা তালিকা, যাতে ফর্মে দুইটা দল দেখানো যায়: "নগদে"
             * আর "বাকিতে"। ⚠️ এক তালিকায় মিশিয়ে দিলে ব্যবহারকারী
             * বুঝতেন না যে দুইটার ফল **সম্পূর্ণ আলাদা** — একটায় টাকা
             * এখনই যায়, অন্যটায় দেনা তৈরি হয়।
             */
            'creditAccounts' => (function () use ($all) {
                /*
                 * ⚠️ ── `PAYABLE_GROUP`, `PAYABLE` নয় — আর পার্থক্যটা মনে রাখার মতো ──
                 *
                 * **কোড ধরে একটা খাত খুঁজলে `PAYABLE`; গোটা পরিবার চাইলে
                 * `PAYABLE_GROUP`।** ⓘ যে পোস্ট করে সে একটা ঘর চায়; যে
                 * তালিকা বা যাচাই করে সে পরিবার চায়।
                 *
                 * ⓘ এখানে আগে `PAYABLE` লেখা ছিল, আর তখন সেটা `2110`-ই
                 * ছিল — অর্থাৎ কোডটা ঠিকই দল ধরে হাঁটত। প্রদেয় চার ঘরে
                 * ভাগ হওয়ার দিন `PAYABLE` নেমে গেল `2111`-এ, আর এই
                 * তালিকাটা **নীরবে চার থেকে এক** হয়ে গেল: পরিবহন ও
                 * হাম্মালির দেনা ফর্ম থেকে অগম্য হয়ে পড়ল।
                 */
                $payable = StandardChart::find(StandardChart::PAYABLE_GROUP);

                if ($payable === null) {
                    return collect();
                }

                /*
                 * ⚠️ `2110` নিজে একটা **গ্রুপ** — ওতে দাখিলা বসে না।
                 * আসল খাতগুলো তার নিচে: পরিবহন · হাম্মালি · ব্যবসায়িক
                 * সরবরাহকারী · সেবা।
                 *
                 * ⭐ আর এটাই ব্যবহারকারীর জন্য ভালো: "কোন প্রদেয়" বাছা
                 * গেলে পরিবহনের দেনা আর হাম্মালির দেনা আলাদা থাকে, আর
                 * স্থিতিপত্রে ওগুলো আলাদা সারি হয়।
                 *
                 * ⓘ বংশধর ধরে, এক ধাপ নয় — ছকটা ক্রেতা নিজে বাড়াতে
                 * পারেন, আর তখন নাতির ঘরে বসানো খাত তালিকা থেকে
                 * হারিয়ে যেত (একই ফাঁদ [[AccountsFacts::assetValue]]-এ
                 * ধরা পড়েছিল)।
                 */
                $ids = $payable->selfAndDescendants()->pluck('id')->all();

                return $all->whereIn('id', $ids)->values();
            })(),
            'incomeAccounts' => $all->where('type', Account::INCOME)->values(),
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),

            /*
             * এখানে একসময় 'customers' নামে একটা তালিকা যেত — ৫০০ জন
             * গ্রাহক, প্রতিটা ভাউচার ফর্মে। কোনো পর্দা ওটা পড়ত না।
             *
             * ── কেন ওটা তালিকায় ফিরবে না ────────────────────────────
             * ভাউচারের পক্ষ বাছা হয় **হিসাবের খাত** ধরে, গ্রাহক ধরে নয়
             * ("প্রাপ্য হিসাব" ডেবিট হয়, "করিম স্টোর" নয়)। তাই
             * তালিকাটা কেবল অব্যবহৃতই ছিল না, ভুল ধারণারও ছিল।
             *
             * আর এটাই Accounts-কে Customer-এর উপর দাঁড় করিয়ে রেখেছিল,
             * অথচ Accounts কারও উপর দাঁড়ায় না — সবাই তার উপর দাঁড়ায়।
             * একটা অব্যবহৃত লাইনের জন্য পুরো নির্ভরতার ক্রমটা উল্টে
             * ছিল, আর BoundariesTest সেটাই ধরল।
             */
            'sides' => $this->sidesFor($type),

            /*
             * টাকার শ্রেণি — দুইটা ড্রপডাউনের কাঁচামাল।
             *
             * ⭐ দুইটা আলাদা কোয়েরি নয়, **একটাই** — মা ও সন্তান একই
             * টেবিলের সারি, তাই সবগুলো একবারে এনে ব্রাউজারেই ভাগ করা
             * হয় (Sub Category-র তালিকা Category বাছার সাথে সাথে বদলায়,
             * আর প্রতিবার সার্ভারে গেলে ফর্মটা থেমে থেমে চলত)।
             *
             * ⓘ সারির সাথে `account_id` যায়, কারণ শ্রেণি বাছলেই খাতের
             * ড্রপডাউনটা ভরে যাওয়া দরকার। খাতহীন মা-শ্রেণির সন্তান
             * মায়ের খাত পায় — যুক্তিটা এক জায়গায়, [[MoneyCategory::resolvedAccountId()]]
             * -এ, আর এখানে তার ফলটাই পাঠানো হয়।
             *
             * ⚠️ জাবেদা ভাউচারে এই ঘর দুইটা নেই — ওখানে প্রতিটা সারির
             * নিজের খাত, তাই শ্রেণি থেকে খাত বসানোর প্রশ্নই ওঠে না।
             */
            'moneyCategories' => $type === Voucher::JOURNAL
                ? collect()
                : MoneyCategory::query()
                    ->with('parent')
                    ->active()
                    ->whereIn('context', [$this->categoryContextFor($type), MoneyCategory::BOTH])
                    ->orderBy('code')
                    ->get()
                    ->map(fn (MoneyCategory $row) => [
                        'id' => (int) $row->getKey(),
                        'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                        'label' => $row->name(),
                        'account_id' => $row->resolvedAccountId(),
                    ])
                    ->values(),
        ];
    }

    /**
     * এই ধরনের ভাউচারে কোন প্রসঙ্গের শ্রেণি দেখানো হবে।
     *
     * ⚠️ আদায়ে প্রদানের শ্রেণি দেখালে ব্যবহারকারী "সরবরাহকারীকে অগ্রিম"
     * বেছে ফেলতে পারতেন, আর তখন টাকার দিক উল্টো বসত — খাতা তবু মিলত,
     * কেবল উত্তরটা মিথ্যা হত।
     *
     * ⓘ খরচ ও কন্ট্রা ভাউচারও টাকা বের করে, তাই ওগুলোও প্রদানের পাশে।
     */
    private function categoryContextFor(string $type): string
    {
        return $type === Voucher::RECEIPT
            ? MoneyCategory::RECEIPT
            : MoneyCategory::PAYMENT;
    }

    /**
     * ফর্মের দুইটা ঘরের লেবেল ও তালিকা।
     *
     * চারটা ধরনেই হিসাবটা এক ("to" ডেবিট, "from" ক্রেডিট), কিন্তু
     * পর্দার ভাষা আলাদা হতে হবে: ক্যাশিয়ার "কার কাছ থেকে" বোঝে,
     * "ক্রেডিট" বোঝে না।
     *
     * @return array{from: array{label: string, source: string}, to: array{label: string, source: string}}
     */
    private function sidesFor(string $type): array
    {
        return match ($type) {
            // টাকা এল গ্রাহক/আয় থেকে, গেল ক্যাশ বা ব্যাংকে
            Voucher::RECEIPT => [
                'from' => ['label' => 'accounts::field.received_from', 'source' => 'party_or_income'],
                'to' => ['label' => 'accounts::field.paid_into', 'source' => 'money'],
            ],
            // টাকা এল ক্যাশ/ব্যাংক থেকে, গেল সরবরাহকারী বা দায়ে
            Voucher::PAYMENT => [
                'from' => ['label' => 'accounts::field.paid_from', 'source' => 'money'],
                'to' => ['label' => 'accounts::field.paid_to', 'source' => 'all'],
            ],
            /*
             * ── খরচ: নগদে, নাকি বাকিতে ──────────────────────────────
             *
             * ⚠️ আগে `from` কেবল টাকার খাত নিত — অর্থাৎ **প্রতিটা খরচ
             * ধরে নেওয়া হত তখনই মেটানো হয়েছে**। হাম্মালির বিল বা
             * দালালের কমিশন ডিপোতে মাসে একবার মেটে, তাই ওগুলো লেখাই
             * যেত না।
             *
             * ⭐ খরচটা ঘটে **যেদিন কাজটা হয়**, টাকা দেওয়ার দিন নয়।
             * বাকিতে লিখলে দায় বসে, আর মেটানোর দিন সেটা শোধ হয়।
             */
            Voucher::EXPENSE => [
                'from' => ['label' => 'accounts::field.paid_from', 'source' => 'money_or_credit'],
                'to' => ['label' => 'accounts::field.expense_head', 'source' => 'expense'],
            ],
            // দুই দিকেই টাকার খাত — এটাই কন্ট্রার সংজ্ঞা
            Voucher::CONTRA => [
                'from' => ['label' => 'accounts::field.moved_from', 'source' => 'money'],
                'to' => ['label' => 'accounts::field.moved_to', 'source' => 'money'],
            ],
            default => [
                'from' => ['label' => 'accounts::field.from_account', 'source' => 'all'],
                'to' => ['label' => 'accounts::field.to_account', 'source' => 'all'],
            ],
        };
    }

    private function resolveParty(Voucher $voucher): ?object
    {
        if ($voucher->party_type === null || $voucher->party_id === null) {
            return null;
        }

        return app(DrillResolver::class)
            ->resolve($voucher->party_type, $voucher->party_id);
    }

    private function assertEditable(Voucher $voucher): void
    {
        abort_unless($voucher->isEditable(), 403, __('accounts::validation.posted_cannot_edit', [
            'no' => $voucher->document_no,
        ]));
    }

    private function assertType(string $type): string
    {
        abort_unless(in_array($type, Voucher::TYPES, true), 404);

        return $type;
    }
}
