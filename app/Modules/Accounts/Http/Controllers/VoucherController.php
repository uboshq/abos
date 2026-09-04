<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Branch;
use App\Modules\Accounts\Http\Requests\VoucherRequest;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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

        $query = Voucher::query()
            ->ofType($type)
            ->search($request->query('q'))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('trx_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('trx_date', '<=', $d))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));

        $sort = $this->applySort($query, $request, $this->sorts());

        $vouchers = $query->paginate(50)->withQueryString();

        return view('accounts::voucher.index', [
            'menu' => $this->menu->forUser($request->user()),
            'type' => $type,
            'vouchers' => $vouchers,
            'q' => $request->query('q'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
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
            'voucher' => new Voucher(['type' => $type, 'trx_date' => now()]),
            ...$this->formOptions($type),
        ]);
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

        $this->vouchers->update($voucher, $request->validated(), $this->linesFrom($request, $voucher->type));

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

        return [
            'moneyAccounts' => $money,
            'allAccounts' => $all,

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
        ];
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
                'to' => ['label' => 'accounts::field.received_into', 'source' => 'money'],
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
