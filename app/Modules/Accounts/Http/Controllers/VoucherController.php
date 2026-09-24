<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Modules\Accounts\Http\Requests\VoucherRequest;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\DepositFormOptions;
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
    /**
     * জমার ফরমের তালিকাগুলো — এখন [[DepositFormOptions]] থেকে।
     *
     * ── ⭐ কেন সরানো হলো, ২৫ সেপ্টেম্বর ২০২৬ ────────────────────────
     * মালিকের নির্দেশে সরাসরি বিক্রয়ের পর্দায় **"জমা যোগ"** বোতাম বসছে,
     * আর সেখানে আদায় ভাউচারের **হুবহু একই** পপ-আপ খুলবে।
     *
     * ⓘ "হুবহু একই" মানে একই ব্লেড অংশ, আর সেটা চলে এই তালিকাগুলোর
     * উপর। ⛔ পদ্ধতিটা `private` ছিল, তাই বিক্রয় মডিউল ডাকতেই পারত না।
     *
     * ⚠️ বিক্রয়ের দিকে আবার লিখলে **দুইটা সত্য** হত — আর তখন এক পর্দায়
     * একটা নগদ খাত দেখা যেত, অন্যটায় নয়, কোনো ত্রুটি ছাড়াই। ⓘ বিশেষ
     * করে নগদের ছাঁকনিটা (নিজের ক্যাশবাক্স) হারালে নগদে অনুমোদন তুলে
     * দেওয়ার **শর্তটাই** উবে যেত।
     *
     * ⓘ `$type` ঘরটা রাখা হয়েছে ডাকনেওয়ালাদের অপরিবর্তিত রাখতে;
     * তালিকাগুলো ধরন ধরে বদলায় না।
     *
     * @return array<string, mixed>
     */
    private function formOptions(string $type): array
    {
        return app(DepositFormOptions::class)->all($type);
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

    /**
     * ফর্মের দুইটা ঘরের লেবেল ও তালিকা।
     *
     * চারটা ধরনেই হিসাবটা এক ("to" ডেবিট, "from" ক্রেডিট), কিন্তু
     * পর্দার ভাষা আলাদা হতে হবে: ক্যাশিয়ার "কার কাছ থেকে" বোঝে,
     * "ক্রেডিট" বোঝে না।
     *
     * @return array{from: array{label: string, source: string}, to: array{label: string, source: string}}
     */
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
