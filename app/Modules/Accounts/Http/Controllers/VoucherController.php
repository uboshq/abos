<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\Contracts\PartyOpenBills;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Services\PostedEdit;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Modules\Accounts\Http\Requests\VoucherRequest;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\DepositFormOptions;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
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
    use GrandTotals;
    use SortsLists;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly MenuBuilder $menu,
        private readonly VoucherWriter $writer,
    ) {}

    public static function middleware(): array
    {
        return [
            /*
             * ⓘ চাবি এখন নীতির ভিতরে ([[VoucherPolicy]]), দরজায় নয় — ২৮ সেপ্টেম্বর ২০২৬।
             * ⚠️ কোন চাবি কী পাহারা দেয় তা হুবহু আগের মতো: দেখা `accounts.report`,
             * লেখা `accounts.voucher.create`, বদল ও পোস্ট `…update`, বাতিল `…delete`।
             * ⓘ কেন সরানো: কাগজপত্রের ঘরও একই নীতি জিজ্ঞেস করে; দরজা আর ঘর এক
             * জায়গা থেকে উত্তর পায়, তাই কোনোদিন দুই রকম বলতে পারে না।
             */
            new Middleware('can:viewAny,'.Voucher::class, only: ['index']),
            new Middleware('can:view,voucher', only: ['show']),
            new Middleware('can:create,'.Voucher::class, only: ['create', 'store']),
            new Middleware('can:update,voucher', only: ['edit', 'update', 'post']),
            new Middleware('can:delete,voucher', only: ['cancel']),

            // ⓘ পাকা ভাউচার সম্পাদনা — দেখার চাবি দরজায়, আর সুপার অ্যাডমিন + সুইচ + খোলা মাস [[assertMayRevise()]]-এ
            new Middleware('can:view,voucher', only: ['revise', 'saveRevision']),
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
            ->when($request->query('from'), fn ($q, $d) => $q->where('trx_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('trx_date', '<=', $d))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            /*
             * ⛔ খালি তালিকায় `whereIn` দিলে শূন্য সারি আসে, আর সেটাই
             * ঠিক: ঝুলে থাকা কিছু না থাকলে ছাঁকনিটাও খালি দেখাবে।
             */
            ->when($request->boolean('awaiting'), fn ($q) => $q->whereIn('id', $awaitingIds))
            // ⭐ কেবল মাসশেষের সমন্বয় — জাবেদার তালিকায় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
            ->when($type === Voucher::JOURNAL && $request->boolean('adjusting'), fn ($q) => $q->where('is_adjusting', true));

        $sort = $this->applySort($query, $request, $this->sorts());

        // ⭐ সর্বমোট — ছাঁকা তালিকার সব পাতা মিলে, পাতা ভাঙার আগে ([[GrandTotals]])
        $grand = $this->grandTotals($query, ['amount' => 't.amount']);

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
            'grand' => $grand,
            'q' => $request->query('q'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
            'awaitingCount' => $awaitingIds->count(),
            'awaitingIds' => $awaitingIds->map(fn ($id) => (int) $id)->all(),
            'awaiting' => $request->boolean('awaiting'),
            'adjusting' => $type === Voucher::JOURNAL && $request->boolean('adjusting'),

            // ⭐ "সংশোধিত" দাগ — এই পাতার ভাউচারগুলোর, একটা প্রশ্নে (পাকা ভাউচার সম্পাদনা, ৫ অক্টোবর ২০২৬)
            'revisedIds' => DocumentRevision::query()
                ->where('document_type', (new Voucher)->getMorphClass())
                ->whereIn('document_id', $vouchers->getCollection()->modelKeys())
                ->distinct()->pluck('document_id')->map(fn ($id) => (int) $id)->all(),
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
     * এই পক্ষের যে বিলগুলো এখনো পুরো শোধ হয়নি — বিলের মালিক মডিউল মাপে ([[PartyOpenBills]])।
     *
     * ── ⛔ কী ভাঙা ছিল — Accounts-Finance অডিট ম১, ৪ অক্টোবর ২০২৬ ─────────
     * এখানে বিক্রয়ের টেবিলে কাঁচা কোয়েরি চলত, বিলের অবস্থা খুঁজত 'posted' — অথচ পাকা বিক্রয় বিল 'confirmed'। ⚠️ তালিকা তাই
     * সবসময় খালি, আর বাকি মাপা হত নিজের মতো (পাকা ফেরত বাদ যেত না)। ফর্ম আবার বহু বিলের ভাগ পাঠাত, যা কোথাও সংরক্ষণ হত না।
     *
     * ⭐ এখন: তালিকা বিলের নিজের বাকিতে, আর রসিদ **একটা** বিলের বিপরীতে বাঁধা যায় (`against_type`/`against_id`), যার অঙ্ক,
     * পক্ষ আর খোলা থাকা পোস্টের মুহূর্তে মাপে [[VoucherService::assertAgainstFits()]]। এক টাকায় বহু বিল আর "পুরনো বিল আগে"
     * বিক্রয়ের "আদায়" পর্দায় — হিসাবের উৎস একটাই।
     *
     * @return list<array<string, mixed>>
     */
    private function openBillsOf(string $type, int $id): array
    {
        return app(PartyOpenBills::class)->openBills($type, $id);
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
        /*
         * ⭐ এক পথ — ওয়েব আর ফোন একই লেখকের হাতে ([[VoucherWriter::store()]]; মালিক, ৭ অক্টোবর ২০২৬: ফোনে সব ভাউচার, ওয়েবের
         * একই নিয়মে)। ধাপ আর তাদের কারণ সেখানে: ছাঁচ (৩ক), বিলের ভাগ, সংযুক্তি, খসড়া, সই, লেখক ≠ পাকাকারী (৩গ), পাকা — এক লেনদেনে।
         */
        [$voucher, $waiting] = $this->writer->store(
            $data,
            $this->linesFrom($request, $type),
            $request->boolean('save_as_draft'),
            $request->hasFile('attachment') ? $request->file('attachment') : null,
        );

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

        // ⭐ একই লেখকের হাতে, এক লেনদেনে ([[VoucherWriter::update()]]) — সম্পাদনার পরেও সই আর লেখক ≠ পাকাকারীর একই শর্ত
        $waiting = $this->writer->update(
            $voucher,
            $validated,
            $this->linesFrom($request, $voucher->type),
            $request->boolean('save_as_draft'),
            $request->hasFile('attachment') ? $request->file('attachment') : null,
        );

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

        /*
         * ⛔ কেবল খসড়ায় — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ আগে নম্বরটা আগে সংরক্ষণ হত, আর "আগেই পোস্ট হয়েছে" ধরা পড়ত
         * তার পরে ([[VoucherService::post()]])। ফলে পোস্ট-হওয়া ভাউচারেও এই
         * দরজায় নতুন নম্বর পাঠালে নম্বরটা বদলে যেত — তারপর ভুলবার্তা।
         * ⓘ নম্বর ছাপের বাইরে ([[Voucher::fingerprintIgnores()]]) কেবল
         * পোস্ট হওয়া পর্যন্ত; তারপর ওটা আর নড়ে না।
         */
        // ⭐ লেনদেন নম্বর কেবল খসড়ায়, তালায় আবার পড়ে, তারপর সই, তারপর সেবা — একই লেখকের হাতে ([[VoucherWriter::post()]])
        $stopping = $this->writer->post($voucher, $validated['instrument_no'] ?? null);

        if ($stopping !== null) {
            return back()->with('warning', $stopping->status === Approval::REJECTED
                ? __('accounts::message.voucher_approval_rejected', [
                    'no' => $voucher->document_no,
                    'reason' => (string) $stopping->decisions()->latest('id')->value('remarks'),
                ])
                : __('accounts::message.voucher_approval_pending', ['no' => $voucher->document_no]));
        }

        return back()->with('saved', __('accounts::message.voucher_posted', ['no' => $voucher->document_no]));
    }

    /** বাতিল — বিপরীত এন্ট্রি দিয়ে, মুছে নয় (নিয়ম ৫)। */
    public function cancel(Request $request, Voucher $voucher): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        /*
         * ⭐ পাকা ভাউচার — উল্টো কাগজ, নিজের নম্বরে (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬: পাকা কাগজ বদলায় না)।
         * ⓘ খসড়া খাতায় বসেনি — তার বাতিল আগের মতো সাধারণ।
         */
        if ($voucher->isPosted() && ! $voucher->isCancelled()) {
            $paper = app(\App\Modules\Accounts\Services\AccountsReversalService::class)
                ->reverseVoucher($voucher, $request->user(), $validated['cancel_reason']);

            return back()->with('saved', __('accounts::reversal.saved', ['no' => $voucher->document_no, 'rev' => $paper->document_no]));
        }

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
    /** @param  bool|'checker'  $waiting  ⓘ 'checker' = লেখক নিজে পাকা করেন না (অংশ ৩গ), খসড়া অন্যের অপেক্ষায় */
    private function savedOrWaiting(Voucher $voucher, bool|string $waiting): array
    {
        return match ($waiting) {
            'checker' => ['warning', __('accounts::message.voucher_awaits_another_hand', ['no' => $voucher->document_no])],
            true => ['warning', __('accounts::message.voucher_approval_pending', ['no' => $voucher->document_no])],
            default => ['saved', __('accounts::message.voucher_saved', ['no' => $voucher->document_no])],
        };
    }

    /**
     * সহজ ফর্মের দুইটা খাতকে দুইটা সারিতে বদলানো।
     *
     * @return list<array<string, mixed>>
     */
    private function linesFrom(VoucherRequest $request, string $type): array
    {
        // ⓘ ফোনের সিঙ্কের একই রূপান্তর ([[VoucherWriter::linesFor()]]) — খালি চার্জের ঘর মানে চার্জ নেই, `0` নয়
        return $this->writer->linesFor($type, $request->all());
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

    /**
     * ⭐ পোস্ট হওয়া ভাউচার সম্পাদনার পাতা — সুপার অ্যাডমিন (মালিকের আদেশ, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ খসড়ার একই ফর্ম, সাথে বাধ্যতামূলক কারণ; সংরক্ষণে আগের দাখিলা উল্টো সারিতে থাকে আর নতুনটা বসে
     * ([[VoucherService::editPosted()]], [[RevisionKeeper]])।
     */
    public function revise(Request $request, Voucher $voucher): View
    {
        $this->assertMayRevise($request->user(), $voucher);

        $voucher->load('lines');

        return view($voucher->type === Voucher::JOURNAL
            ? 'accounts::voucher.journal-form'
            : 'accounts::voucher.simple-form', [
                'menu' => $this->menu->forUser($request->user()),
                'type' => $voucher->type,
                'voucher' => $voucher,
                'revising' => true,
                ...$this->formOptions($voucher->type),
            ]);
    }

    public function saveRevision(VoucherRequest $request, Voucher $voucher): RedirectResponse
    {
        $this->assertMayRevise($request->user(), $voucher);

        $reason = $request->validate([
            'revision_reason' => ['required', 'string', 'min:3', 'max:500'],
        ])['revision_reason'];

        $validated = $request->validated();

        $this->vouchers->editPosted(
            $voucher,
            $request->user(),
            (string) $reason,
            // ⓘ কেবল মাথার ঘর — সারি, চালান-ভাগ আর বোতামের নাম আলাদা পথে যায়
            array_intersect_key($validated, array_flip($voucher->getFillable())),
            $this->linesFrom($request, $voucher->type),
            // ⓘ খরচে চালান-ভাগ সংশোধনের একই লেনদেনে; অন্য ধরনে ঘরটাই নেই, তাই ছোঁয়া হয় না
            $voucher->type === Voucher::EXPENSE ? ($validated['bill_shares'] ?? []) : null,
            $validated['alloc_basis'] ?? 'qty',
        );

        return redirect()
            ->route('accounts.voucher.show', $voucher)
            ->with('saved', __('accounts::revision.saved', ['no' => $voucher->document_no]));
    }

    /**
     * ⛔ সুইচ চালু, সুপার অ্যাডমিন, পোস্ট হওয়া, খোলা মাস ([[PostedEdit::assertMay()]]), আর ভাউচারটা অন্য কিছুর সাথে বাঁধা নয়
     * ([[VoucherService::whyNotRevisable()]]) — না হলে ৪০৩, কারণসহ।
     */
    private function assertMayRevise(?User $user, Voucher $voucher): void
    {
        abort_if($user === null, 403);

        try {
            app(PostedEdit::class)->assertMay($voucher, $user);
        } catch (ValidationException $e) {
            abort(403, (string) collect($e->errors())->flatten()->first());
        }

        $why = $this->vouchers->whyNotRevisable($voucher);
        abort_if($why !== null, 403, (string) $why);
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
