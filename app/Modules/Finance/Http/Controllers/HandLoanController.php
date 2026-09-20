<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use App\Modules\MasterData\Services\PersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * হাতধার — কে আমার কাছে পায়, আর আমি কার কাছে পাই।
 *
 * ── কেন একটাই তালিকা ─────────────────────────────────────────────────
 * দুইটা রিপোর্ট হলে কেউ ওদের মিলিয়ে দেখত না, আর একই মানুষ দুই
 * তালিকায় থাকতে পারত। চিহ্নটাই ভাগ করে দেয়।
 */
class HandLoanController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly HandLoanService $loans,
        private readonly PersonResolver $people,
        private readonly AttachmentEngine $attachments,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.hand_loan.view', only: ['index', 'show']),
            new Middleware('can:finance.hand_loan.create', only: ['create', 'store']),
            new Middleware('can:finance.hand_loan.move', only: ['move', 'settle']),
        ];
    }

    /**
     * কে পায়, কাকে দিতে হবে — সবার অবস্থান এক পর্দায়।
     *
     * ── কেন এখানে পাতা ভাগ নেই ──────────────────────────────────────
     * উপরের তিনটা সংখ্যা (মোট প্রাপ্য, মোট দেয়, কতজন) আসে নিচের ঠিক
     * এই তালিকাটা থেকেই। পাতা ভাগ করলে ওগুলো **এই পাতার** সংখ্যা হয়ে
     * যেত, অথচ লেখা থাকত "মোট" — পাতা বদলালে মোট বদলাত।
     *
     * আর সারিগুলো জমে না: একটা সারি মানে একজন মানুষ যাঁর সাথে হিসাব
     * এখনো খোলা, আর চুকে গেলে সারিটা নিজে থেকেই চলে যায় (`open()`)।
     * সংখ্যাটা দশে গোনা, হাজারে নয়।
     *
     * ⓘ [[HandLoanService::standing]]-এ প্রতি সারির কোয়েরির হিসাবটা আর
     * কী শর্তে সেটা একদিন বদলাতে হবে, দুইটাই লেখা আছে।
     */
    public function index(Request $request): View
    {
        $standing = $this->loans->standing();

        /*
         * ⭐ খোঁজা — তালিকা টুলবারে এল, মালিকের নির্দেশে (১৯ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ নাম, কোড বা মোবাইল ধরে, মেমরিতে — তালিকাটা খোলা হাতধারের, দশে
         * গোনা ([[HandLoanService::standing()]]-এর মন্তব্য)। ⚠️ উপরের দুই
         * যোগফল ছাঁকা হয় না: "কত পাব, কত দেব" প্রশ্নটা পুরো প্রতিষ্ঠানের।
         */
        $term = mb_strtolower(trim((string) $request->query('q')));

        $rows = $term === '' ? $standing['rows'] : array_values(array_filter(
            $standing['rows'],
            fn (array $row) => str_contains(mb_strtolower(implode(' ', array_filter([
                $row['account']->person?->name_en,
                $row['account']->person?->name_bn,
                $row['account']->person?->code,
                $row['account']->person?->mobile,
            ]))), $term),
        ));

        /*
         * ⭐ ট্যাব — "তারা দেবে · আমরা দেব" (মালিকের নমুনা: মূলধনের পাতা, ১৯ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ দিকটা চিহ্ন থেকে, [[partials/side]]-এর মতোই। ⚠️ "সব"-ও থাকে —
         * শোধ হয়ে যাওয়া (শূন্য) খাতা দুই দিকের কোনোটাতেই পড়ে না, আর
         * ট্যাব দুইটা হলে ওরা পর্দা থেকে হারাত।
         */
        $sideOf = fn (array $row) => bccomp((string) $row['balance'], '0', 4);

        /*
         * ⭐ মনে করিয়ে দেওয়া — অর্থের মানচিত্র §১৪খ, ২০ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ কোনগুলো ─────────────────────────────────────────────────
         * যার হিসাব এখনো চুকে যায়নি, আর তারিখ পেরিয়ে গেছে বা ত্রিশ দিনের
         * ভিতরে আসছে। ⚠️ তারিখটা পরের কিস্তির (`next_due_on`), না থাকলে
         * চুক্তির শেষ দিন — "কাকে এখন ফোন করতে হবে" প্রশ্নের উত্তর ঐটাই।
         *
         * ⛔ তারিখহীন ধার এখানে আসে না: *"যখন পারো দিও"* ধরনের ধারে মনে
         * করিয়ে দেওয়ার কিছু নেই, আর ওগুলো তালিকায় ভরলে সত্যিকারের
         * তাগাদাগুলো চোখ এড়াত।
         */
        $soon = now()->addDays(30)->startOfDay();

        $needsChasing = function (array $row) use ($sideOf, $soon): bool {
            if ($sideOf($row) === 0) {
                return false;
            }

            $due = $row['account']->next_due_on ?? $row['account']->due_on;

            return $due !== null && $due->lte($soon);
        };

        $counts = [
            'all' => count($rows),
            'they' => count(array_filter($rows, fn ($r) => $sideOf($r) > 0)),
            'we' => count(array_filter($rows, fn ($r) => $sideOf($r) < 0)),
            'due' => count(array_filter($rows, $needsChasing)),
        ];

        $tab = in_array($request->query('tab'), ['they', 'we', 'due'], true)
            ? (string) $request->query('tab')
            : 'all';

        if ($tab !== 'all') {
            $rows = array_values(array_filter($rows, match ($tab) {
                'they' => fn ($r) => $sideOf($r) > 0,
                'we' => fn ($r) => $sideOf($r) < 0,
                default => $needsChasing,
            }));
        }

        return view('finance::hand-loan.index', [
            'menu' => $this->menu->forUser($request->user()),
            'standing' => $standing,
            'rows' => $rows,
            'tab' => $tab,
            'counts' => $counts,
        ]);
    }

    /**
     * নতুন হাতধারের ফর্ম — নিজের পাতায় (১৯ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ আগে ফর্মটা তালিকার পাতার উপরে বসত, আর মালিক তালিকাটাই খুঁজে
     * পাচ্ছিলেন না (*"এগুলোর লিস্ট কোথায়?"*)। এখন অন্য মডিউলের মতো।
     */
    public function create(Request $request): View
    {
        return view('finance::hand-loan.create', [
            'menu' => $this->menu->forUser($request->user()),
            'people' => Person::query()->active()->orderBy('name_en')
                ->pluck('name_en', 'id'),
            'accounts' => $this->moneyAccounts(),

            /*
             * ⭐ পক্ষের সাথে জোড়ার তালিকা — মানচিত্র §১৪খ, ২০ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ চাবিটা "customer:12" ছাঁদে, তাই একটাই ঘরে দুই রকম পক্ষ ধরে —
             * পর্দায় কোনো Alpine লাগে না। ⚠️ কেবল সক্রিয়রা, আর নামে সাজানো।
             */
            'parties' => $this->parties(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            /*
             * ⓘ দুইটা পথ, একটাই লাগে — তালিকা থেকে বাছা, নয় নতুন নাম।
             * ⚠️ `exists`-এ `company_id`, নাহলে অন্য কোম্পানির আইডি বসিয়ে
             * দিলে সেই মানুষের নামে এখানকার হিসাব বসত।
             */
            'person_id' => ['nullable', 'integer', 'required_without:person_new',
                Rule::exists('mdm_people', 'id')->where('company_id', $companyId)],
            'person_new' => ['nullable', 'string', 'max:120', 'required_without:person_id'],
            'person_mobile' => ['nullable', 'string', 'max:32'],

            /*
             * ⭐ পক্ষের তিনটা ঘর — মানুষটার সাথে যায়
             * ([[App\Modules\MasterData\Services\PersonResolver]])।
             */
            'person_relationship' => ['nullable', 'string', 'max:60'],
            'person_address' => ['nullable', 'string', 'max:191'],
            'person_nid_tin' => ['nullable', 'string', 'max:40'],

            /*
             * ⭐ নমুনার তিনটা ঘর — ১৫ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ `opening_repaid` — নামটা সৎ রাখা হয়েছে। এটা **খোলার
             * জের**, চলতি ব্যালান্স নয়: পুরনো খাতা ব্যবস্থায় তোলার সময়
             * যেটুকু আগে ফেরত এসেছে সেটুকু। ⛔ এরপর থেকে হিসাব রাখে
             * খতিয়ান, এই ঘরটা নয়।
             */
            'principal' => ['nullable', 'numeric', 'min:0'],
            'opening_repaid' => ['nullable', 'numeric', 'min:0'],
            'money_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'paper' => ['nullable', 'file'],

            'note' => ['nullable', 'string', 'max:500'],

            /*
             * ⭐ পক্ষের সাথে জোড়া — অর্থের মানচিত্র §১৪খ, ২০ সেপ্টেম্বর ২০২৬।
             *
             * ── ⛔ কলামটা ছিল, পর্দা ছিল না ─────────────────────────────
             * `partner_id`/`partner_type` অনেক দিন ধরেই সারিতে আছে আর সেবাও
             * লেখে, কিন্তু ফর্মে ঘরটা কেউ আঁকেনি — তাই মান কখনো আসতই না।
             *
             * ⓘ একটাই ঘর, "customer:12" ছাঁদে — দুইটা ঘর (ধরন + তালিকা) হলে
             * পর্দায় Alpine লাগত, আর CSP-র নিয়মে ওটা বাড়তি ঝুঁকি।
             *
             * ⚠️ কেন জোড়াটা দরকার: একই মানুষ প্রায়ই একসাথে ডিলার আর
             * ধারদাতা। ⓘ জোড়া থাকলে তাঁর হাতধার আর তাঁর বাকির হিসাব এক
             * নামে মেলানো যায়; না থাকলে দুইটা আলাদা মানুষ মনে হত।
             */
            'party' => ['nullable', 'string', 'regex:/^(customer|supplier):[0-9]+$/'],

            /*
             * ⭐ ধারের শর্তগুলো — ১৫ সেপ্টেম্বর ২০২৬-এ যোগ করা।
             *
             * ⛔ এতদিন এই পর্দায় কেবল **কে** আর **কত নোট** চাওয়া হত।
             * ⚠️ অর্থাৎ *"সুদ কত"*, *"কবে ফেরত"*, *"কাগজ কী"* — তিনটার
             * একটারও উত্তর খাতায় থাকত না, আর প্রশ্নগুলো ওঠে ঠিক তখন
             * যখন সম্পর্কটা আর ভালো নেই।
             *
             * ⓘ সবগুলোই ঐচ্ছিক, আর সেটা ইচ্ছাকৃত: পরিচিত মানুষের ধার
             * প্রায়ই সুদবিহীন আর মেয়াদহীন। ⛔ বাধ্যতামূলক করলে মানুষ
             * বানানো সংখ্যা বসাত, আর সেটা না লেখার চেয়েও খারাপ।
             */
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'due_on' => ['nullable', 'date'],
            'next_due_on' => ['nullable', 'date'],
            'repayment' => ['nullable', Rule::in(HandLoanAccount::REPAYMENTS)],
            'security' => ['nullable', Rule::in(HandLoanAccount::SECURITIES)],
        ]);

        /*
         * ⓘ মোবাইলের ঘরটা আর এখানে নেই — নম্বরটা ব্যক্তির সারিতে বসে,
         * আর নতুন নাম লেখার সময় সেটাও একসাথেই নেওয়া হয়
         * ([[App\Modules\MasterData\Services\PersonResolver]])।
         */
        $data['person_id'] = $this->people->resolve($data);

        /*
         * ⓘ "customer:12" → দুইটা ঘরে ([[HandLoanService::open()]] ওদেরই
         * চেনে)। ⚠️ খালি হলে দুইটাই নাল — জোড়া না থাকাটাও একটা উত্তর।
         */
        if (filled($data['party'] ?? null)) {
            [$kind, $id] = explode(':', (string) $data['party']);

            $data['partner_type'] = $kind;
            $data['partner_id'] = (int) $id;
        }

        $account = $this->loans->open($data);

        $this->keepThePaper($request, $account);

        /*
         * খোলার পর তার নিজের পাতায় — পরের কাজটা প্রায় সবসময় ওখানেই,
         * কারণ কেউ কেবল নাম লিখে রাখতে এই পর্দা খোলে না; টাকা দিতে
         * বা নিতে খোলে।
         */
        return redirect()->route('finance.hand_loan.show', $account)
            ->with('saved', __('finance::message.hand_loan_opened', ['who' => $account->person?->name() ?? '']));
    }

    public function show(Request $request, HandLoanAccount $handLoan): View
    {
        return view('finance::hand-loan.show', [
            'menu' => $this->menu->forUser($request->user()),
            'account' => $handLoan,
            'balance' => $this->loans->balanceOf($handLoan),

            /*
             * নতুনটা উপরে — প্রশ্নটা প্রায় সবসময় "শেষ কবে কী হলো"।
             */
            'movements' => $handLoan->movements()
                ->with(['moneyAccount', 'voucher'])
                ->orderByDesc('moved_on')->orderByDesc('id')->get(),

            'accounts' => $this->moneyAccounts(),
        ]);
    }

    public function move(Request $request, HandLoanAccount $handLoan): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'string', 'in:'.implode(',', HandLoanMovement::DIRECTIONS)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'moved_on' => ['required', 'date'],
            'money_account_id' => ['required', 'integer', 'exists:accounts,id'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->loans->move($handLoan, $data);

        return back()->with('saved', __('finance::message.hand_loan_moved'));
    }

    public function settle(HandLoanAccount $handLoan): RedirectResponse
    {
        $this->loans->settle($handLoan);

        return back()->with('saved', __('finance::message.hand_loan_settled', [
            'who' => $handLoan->person?->name() ?? '',
        ]));
    }

    /**
     * পক্ষের সাথে জোড়ার তালিকা — "customer:12" => "গ্রাহক — নাম"।
     *
     * ── ⛔ গ্রাহক-সরবরাহকারীকে নাম ধরে ডাকা হয় না ─────────────────────
     * ⚠️ অর্থ ঐ দুইটা মডিউলের উপর নির্ভর করে না ([[BoundariesTest]])।
     * ⓘ তালিকাটা তাই কোরের [[PartyRegistry]] থেকে — পক্ষের ধরন যে
     * মডিউল ঘোষণা করে, নামটাও সে-ই দেয়।
     *
     * ⓘ চাবিটা "customer:12" ছাঁদে, তাই একটাই ঘরে দুই রকম পক্ষ ধরে —
     * পর্দায় কোনো Alpine লাগে না।
     *
     * @return array<string, string>
     */
    private function parties(): array
    {
        $out = [];

        foreach (app(PartyRegistry::class)->forPicker() as $group) {
            /*
             * ⓘ কেবল গ্রাহক ও সরবরাহকারী — হাতধারের প্রশ্নটা "ইনি কি
             * আমার ব্যবসারও কেউ"। ⛔ কর্মচারী বা ব্যক্তি এখানে নয়:
             * মানুষটা তো উপরের ঘরেই বাছা হচ্ছে, আর দুইবার বাছলে কোনটা
             * আসল সেটা অস্পষ্ট হত।
             */
            if (! in_array($group['type'], ['customer', 'supplier'], true)) {
                continue;
            }

            foreach ($group['options'] as $option) {
                $out[$group['type'].':'.$option['id']] =
                    __('finance::field.party_'.$group['type']).' — '.$option['label'];
            }
        }

        return $out;
    }

    /**
     * টাকার খাতগুলো — নগদ, ব্যাংক, মোবাইল।
     *
     * @return Collection<int, Account>
     */
    private function moneyAccounts(): Collection
    {
        return Account::query()
            ->where('is_group', false)
            ->whereIn('parent_id', Account::query()
                ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
            ->orderBy('code')->get();
    }

    /**
     * ফর্মের সাথে আসা কাগজটা — খাতাটা বসার **পরেই**।
     *
     * ⓘ কাগজ বসে `(উৎস, আইডি)` জোড়ার উপর, আর খাতাটা তৈরি হওয়ার আগে
     * আইডিটাই নেই। ⛔ কাগজ আটকালে খাতাটা থাকে, কেবল সতর্কবার্তা যায় —
     * ⚠️ ধারের খবরটা ছবির চেয়ে দামি, আর কাগজটা পরে খাতার নিজের পাতা
     * থেকে তোলা যায়।
     */
    private function keepThePaper(Request $request, HandLoanAccount $account): void
    {
        if (! $request->hasFile('paper')) {
            return;
        }

        try {
            $this->attachments->store(
                file: $request->file('paper'),
                module: 'finance',
                entity: HandLoanAccount::drillSourceType(),
                entityId: (int) $account->getKey(),
            );
        } catch (AttachmentException $refused) {
            session()->flash('warning', __('core.attachment.refused', [
                'reason' => $refused->getMessage(),
            ]));
        }
    }
}
