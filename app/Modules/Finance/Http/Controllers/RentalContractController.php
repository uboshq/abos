<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\RentalSubjects;
use App\Modules\MasterData\Services\PersonResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;

/**
 * ভাড়ার চুক্তি ও জামানত।
 *
 * ── কেন Finance-এ, আলাদা মডিউলে নয় ──────────────────────────────────
 * জিনিসটা ভাউচার পোস্ট করে, খাত ব্যবহার করে, টাকার খাত চায় — তিনটাই
 * Finance-এর প্লাম্বিং। বাইরে নিলে তিনটাই আবার লিখতে হত, আর একটা
 * হিসাবের রেকর্ড হিসাবের বাইরে বসে থাকত।
 *
 * ── কেন খরচের চাবিটাই, নতুন চাবি নয় ────────────────────────────────
 * ⛔ নতুন `PermissionKey` বসালে সেটা প্রথমে কেউ পেত না — চাবি বিলি হয়
 * enum-এ লেখা ডিফল্ট রোল ধরে, আর নতুন চাবি কোনো রোলে থাকে না। ফল হত
 * একটা লাইভ পর্দা যা সবার জন্য ৪০৩। ⓘ ভাড়া একটা খরচ, আর যিনি খরচ
 * লেখেন তিনিই ভাড়ার চুক্তিও দেখেন।
 */
class RentalContractController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly RentalContractService $contracts,
        private readonly MenuBuilder $menu,
        private readonly AttachmentEngine $attachments,

        /*
         * ⭐ নতুন বাড়িওয়ালা এখান থেকেই — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মালিকের নির্দেশ: *"varar chukti o jamanot e কার সাথে creat
         * er bebosta koro"*। ⚠️ ট্যাবটা তালিকা দেখাত, কিন্তু নতুন নাম
         * যোগ করার পথ ছিল না — মাস্টার ডেটায় গিয়ে বসিয়ে ফিরে আসতে হত।
         *
         * ⛔ আর নামটা এখানে নিজের টেবিলে যায় না: [[PersonResolver]]
         * একটাই মানুষের তালিকায় (`mdm_people`) বসায়। ⓘ নাহলে "Karim",
         * "Karim Mia" আর "করিম মিয়া" তিনজন হয়ে যেতেন, আর একজনের
         * জামানত তিন ভাগে ছিঁড়ত — হাতধারে ঠিক এই কারণেই একই পথ।
         */
        private readonly PersonResolver $people,
    ) {}

    public static function middleware(): array
    {
        return [
            /*
             * ⚠️ ৫ সেপ্টেম্বর ২০২৬ — এখানে আগে `finance.expense.*` লেখা
             * ছিল, আর সেটা **নীরবে ৪০৩** দিত।
             *
             * ⛔ কারণ `finance.expense.create` বলে কোনো চাবিই নেই — খরচ
             * লেখা হয় ভাউচারে, তাই Finance-এ কেবল `expense.view` আছে।
             * Gate অজানা চাবিকে "না" বলে, আর বার্তাটা হয় "This action is
             * unauthorized" — যা পড়ে কেউ বুঝত না চাবিটাই ভুল।
             *
             * ⚠️ আর পর্দাটা `@can('finance.rental.create')` দেখত, অর্থাৎ
             * **বোতামটা দেখা যেত, চাপলে ৪০৩** — সবচেয়ে বিভ্রান্তিকর রূপ।
             *
             * ⓘ কোনো টেস্ট এটা ধরেনি: টেস্টগুলো সার্ভিসটাকে সরাসরি ডাকে,
             * দরজা দিয়ে যায় না। ⭐ ধরা পড়েছে ব্রাউজারে হাতে চালিয়ে —
             * মালিক ঠিক এই কারণেই বলেন "Edge খুলে নিজে দেখো"।
             */
            new Middleware('can:finance.rental.view', only: ['index', 'show']),
            // ⓘ `create` — নতুন চুক্তির নিজের পাতা (১৯ সেপ্টেম্বর ২০২৬), একই চাবি
            new Middleware('can:finance.rental.create', only: [
                'create', 'store', 'adjust', 'revise', 'topUp',
            ]),

            /*
             * চুক্তি শেষ করা আলাদা চাবি — ওটা **বাকি জামানত ফেরত এসেছে
             * বলে খাতায় লেখা**, আর ভুল করে করলে লাখ টাকার একটা পাওনা
             * নীরবে খাতা থেকে মুছে যেত।
             */
            new Middleware('can:finance.rental.close', only: ['close']),
        ];
    }

    public function index(Request $request): View
    {
        /*
         * ⭐ দুইটা ট্যাব — চালু · বন্ধ (মালিক, ১৯ সেপ্টেম্বর ২০২৬:
         * *"সব পাতাতেই সমস্যা"*)। ⓘ আগে "বন্ধগুলোও দেখাও" একটা চেকবক্স ছিল;
         * এখন মূলধনের পাতার মতো ট্যাব, প্রতিটার পাশে গোনা।
         *
         * ⚠️ পুরনো `?closed=1` লিংকও বন্ধের ট্যাবেই খোলে — কারও বুকমার্ক
         * ভাঙে না।
         */
        /*
         * ⭐ তৃতীয় ট্যাব "কার সাথে" — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।
         * ⓘ হাতধারের ট্যাবের হুবহু একই ছাঁদ: একটাই মানুষের তালিকা, তিন দরজা।
         */
        $tab = $request->query('tab') === 'people'
            ? 'people'
            : ($request->query('tab') === 'closed' || $request->boolean('closed')
                ? 'closed' : 'running');

        $query = RentalContract::query()
            ->with(['account', 'expenseAccount'])
            ->when(
                $tab === 'running',
                fn ($q) => $q->active(),
                fn ($q) => $q->where('status', RentalContract::CLOSED),
            )
            /*
             * ⭐ খোঁজা — টুলবারের ঘরটা সত্যিই কাজ করে (১৯ সেপ্টেম্বর ২০২৬)।
             * ⓘ নম্বর, বাড়িওয়ালা, তাঁর ফোন, আর কোন জায়গা (`subject`)।
             */
            ->when(trim((string) $request->query('q')) ?: null, fn ($q, $term) => $q->where(
                fn ($w) => $w->where('document_no', 'like', "%{$term}%")
                    ->orWhere('counterparty', 'like', "%{$term}%")
                    ->orWhere('counterparty_phone', 'like', "%{$term}%")
                    ->orWhere('subject', 'like', "%{$term}%"),
            ))
            ->orderBy('ends_on');

        /*
         * ⭐ এই জায়গার চুক্তি — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ উল্টো দিকটাই আসল লাভ: গুদামের দিক থেকে প্রশ্ন — *"এটার
         * ভাড়া কত, জামানত কত, চুক্তি কবে শেষ"*। ⚠️ ছাঁকনিটা
         * পথে থাকে (`?subject=warehouse:3`), তাই জিনিসটার পাতা থেকে একটা
         * লিংকই যথেষ্ট।
         *
         * ⛔ ছাঁকা হলে ট্যাবটা মানা হয় না, চালু আর বন্ধ দুইটাই আসে:
         * গুদাম ছেড়ে আসার পরও প্রশ্নটা ওঠে — “জামানতটা ফেরত এসেছিল কি” — আর তখন সেটা বন্ধের ঘরে।
         */
        $subject = null;

        if (filled($request->query('subject'))) {
            [$type, $id] = array_pad(explode(':', (string) $request->query('subject'), 2), 2, null);

            if (app(RentalSubjects::class)->knows((string) $type) && (int) $id > 0) {
                $subject = ['type' => (string) $type, 'id' => (int) $id];
            }
        }

        if ($subject !== null) {
            $query = RentalContract::query()
                ->with(['account', 'expenseAccount'])
                ->forSubject($subject['type'], $subject['id'])
                ->orderBy('ends_on');
        }

        /*
         * ⓘ সারি প্রতি একজন মানুষ — কয়টা চুক্তি, মাসে কত ভাড়া,
         * আর কত জামানত তাঁর কাছে পড়ে আছে। ⚠️ শেষেরটাই দামি: চুক্তি
         * শেষ হলে ওই টাকাটা ফেরত আসার কথা।
         */
        $people = $tab !== 'people' ? [] : $this->peopleRows();

        return view('finance::rental.index', [
            'people' => $people,
            'subject' => $subject,
            'subjectSeen' => $subject === null
                ? null
                : app(RentalSubjects::class)->describe($subject['type'], $subject['id']),

            'menu' => $this->menu->forUser($request->user()),
            'contracts' => $query->paginate(50)->withQueryString(),

            /*
             * ⭐ যেগুলো শেষ হয়ে আসছে — তালিকার মাথায়, আলাদা করে।
             *
             * ⚠️ এটাই এই গোটা মডিউলের সবচেয়ে দামি সংখ্যা। বারো লাখ
             * জামানতের নয় লাখ ষাট হাজার ফেরত নিতে ভুলে যাওয়া এভাবেই
             * ঘটে — কাগজটা কোথাও থাকে, তারিখটা কারো মনে থাকে না।
             */
            'endingSoon' => RentalContract::query()->endingSoon()->orderBy('ends_on')->get(),
            'tab' => $tab,

            // ⓘ ট্যাবের পাশের গোনা — খোঁজায় ছাঁকা নয়, মোট কয়টা চুক্তি
            'counts' => [
                'running' => RentalContract::query()->active()->count(),
                'closed' => RentalContract::query()->where('status', RentalContract::CLOSED)->count(),

                // ⓘ কতজন মানুষ — ঠিক যতটা সারি ওই ট্যাবে
                'people' => count($this->peopleRows()),
            ],
        ]);
    }

    /**
     * নতুন চুক্তির ফর্ম — নিজের পাতায় (১৯ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ আগে ফর্মটা তালিকার নিচে বসত, আর তালিকা ও ফর্ম একে অন্যকে চাপা
     * দিত। ⭐ এখন হাতধারের মতো: তালিকায় "+ নতুন চুক্তি", ফর্ম এখানে। ভুল
     * হলে Laravel এই পাতাতেই ফেরায়, পুরনো লেখা সহ।
     */
    public function create(Request $request): View
    {
        return view('finance::rental.create', [
            'menu' => $this->menu->forUser($request->user()),
            'money' => $this->moneyAccounts(),
            'heads' => Account::query()->postable()->active()->orderBy('code')->get(),

            /*
             * ⭐ দুইটা তালিকা — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ *"কার সাথে * কীসের জন্য etar list kothay pabo?"* — দুইটাই
             * মুক্ত-লেখা ঘর ছিল। ⚠️ তাতে একজন বাড়িওয়ালা তিন বানানে তিনজন
             * হয়ে যেতেন, আর একজনকে দেওয়া ভাড়া তিন খাতায় ছড়াত।
             */
            'parties' => $this->parties(),
            'subjects' => app(RentalSubjects::class)->forPicker(),
        ]);
    }

    public function show(Request $request, RentalContract $contract): View
    {
        return view('finance::rental.show', [
            'menu' => $this->menu->forUser($request->user()),
            'contract' => $contract->load(['account', 'expenseAccount']),

            /*
             * নতুন মাস আগে — প্রশ্নটা প্রায় সবসময় "শেষ মাসটা করা
             * হয়েছে কি না", "প্রথমটা কবে" নয়।
             */
            'adjustments' => $contract->adjustments()
                ->with('voucher')
                ->orderByDesc('for_month')
                ->get(),
            'money' => $this->moneyAccounts(),
        ]);
    }

    /**
     * নতুন বাড়িওয়ালা — তালিকা ছেড়ে কোথাও না গিয়ে।
     *
     * ⓘ হাতধারের [[HandLoanController::storePerson]]-এর হুবহু ছাঁচ:
     * একই যাচাই, একই `PersonResolver`, একই `back()`। ⚠️ দুই জায়গায়
     * দুই রকম নিয়ম হলে একই মানুষ দুই পর্দায় দুইভাবে বসতেন।
     */
    public function storePerson(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name_bn' => ['required', 'string', 'max:120'],
            'mobile' => ['nullable', 'string', 'max:32'],
        ]);

        $this->people->resolve([
            'person_new' => $data['name_bn'],
            'person_mobile' => $data['mobile'] ?? null,
        ]);

        return back()->with('saved', __('finance::message.person_added', ['who' => $data['name_bn']]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            /*
             * ⭐ তালিকা থেকে বাছা, নাহলে হাতে লেখা — ২০ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ `party` ঘরটা "person:3" ছাঁদে, হাতধারের মতোই। ⚠️ নামের
             * ঘরটা তবু রয়ে গেছে: তালিকায় নেই এমন বাড়িওয়ালার জন্য,
             * আর পুরনো চুক্তিগুলোর নাম ওখানেই লেখা।
             */
            'party' => ['nullable', 'string', 'max:40'],
            'subject_pick' => ['nullable', 'string', 'max:40'],

            'counterparty' => ['required_without:party', 'nullable', 'string', 'max:191'],
            'counterparty_phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:191'],
            'deposit_amount' => ['required', 'numeric', 'min:0'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'monthly_adjustment' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['required', 'date'],
            'term_months' => ['required', 'integer', 'min:1', 'max:600'],

            /*
             * ⭐ তিনটাই কলামে ছিল, ফর্মে ছিল না — ১৫ সেপ্টেম্বর ২০২৬।
             * ⓘ `rent_day` ২৮-এ থামে: ২৯ বা ৩০ লিখলে ফেব্রুয়ারিতে
             * তারিখটা থাকত না, আর "কত তারিখে" প্রশ্নের উত্তর বছরে
             * একবার মিথ্যা হত।
             */
            'rent_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'advance_months' => ['nullable', 'integer', 'min:0', 'max:36'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paper' => ['nullable', 'file'],
            'account_id' => ['nullable', 'integer'],
            'expense_account_id' => ['nullable', 'integer'],
            'money_account_id' => ['nullable', 'integer'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->splitThePicks($data);

        $contract = $this->contracts->open($data);

        $this->keepThePaper($request, $contract);

        return redirect()
            ->route('finance.rental.show', $contract)
            ->with('saved', __('finance::message.rental_opened'));
    }

    /** এক মাসের ভাড়া — নগদের অংশ আর জামানতের অংশ। */
    public function adjust(Request $request, RentalContract $contract): RedirectResponse
    {
        $this->contracts->adjustMonth($contract, $request->validate([
            'for_month' => ['required', 'date'],
            'paid_on' => ['nullable', 'date'],
            'rent' => ['nullable', 'numeric', 'min:0'],
            'from_deposit' => ['nullable', 'numeric', 'min:0'],
            'money_account_id' => ['nullable', 'integer'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
        ]));

        return back()->with('saved', __('finance::message.rental_month_done'));
    }

    /**
     * শর্ত বদল — ভাড়া বাড়ল বা কমল।
     *
     * ⓘ গত মাসগুলো নড়ে না; বদলটা কেবল সামনের মাসগুলোয় খাটে।
     */
    public function revise(Request $request, RentalContract $contract): RedirectResponse
    {
        $this->contracts->reviseTerms($contract, $request->validate([
            'monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'monthly_adjustment' => ['nullable', 'numeric', 'min:0'],
            'counterparty_phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:500'],
        ]));

        return back()->with('saved', __('finance::message.rental_revised'));
    }

    /** জামানতে আরও টাকা — জায়গা বাড়ল, বা ভাড়া কমানোর বিনিময়ে। */
    public function topUp(Request $request, RentalContract $contract): RedirectResponse
    {
        $this->contracts->addToDeposit($contract, $request->validate([
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'money_account_id' => ['required', 'integer'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'paid_on' => ['nullable', 'date'],
        ]));

        return back()->with('saved', __('finance::message.rental_topped_up'));
    }

    /**
     * চুক্তি শেষ — মেয়াদ ফুরিয়ে, বা আগেই ছেড়ে দিয়ে।
     *
     * ⚠️ টাকার খাতটা এখানে চাওয়া হয় কারণ **ফেরত ভুলে যাওয়াটাই আসল
     * বিপদ**। না দিলে চুক্তি বন্ধ হয়, কিন্তু জামানত খাতে পড়ে থাকে —
     * আর সেটা পর্দায় লেখা থাকে।
     */
    public function close(Request $request, RentalContract $contract): RedirectResponse
    {
        $this->contracts->close($contract, $request->validate([
            'closed_on' => ['nullable', 'date'],
            'money_account_id' => ['nullable', 'integer'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
        ]));

        return back()->with('saved', __('finance::message.rental_closed_done'));
    }

    /**
     * এক সারিতে একজন বাড়িওয়ালা — কয়টা চুক্তি, মাসে কত, জামানত কত।
     *
     * ⓘ সারিগুলো একটাই মানুষের তালিকা (`mdm_people`) থেকে — দ্বিতীয়
     * টেবিল নয়। ⚠️ যে চুক্তিগুলো হাতে লেখা নামে (জোড়া নেই) সেগুলো
     * এই সারিগুলোয় গোনা হয় না — আর সেটাই ঠিক: যাঁর নাম তালিকায় নেই,
     * তাঁর সারিও নেই। ⓘ প্রতিটা চুক্তির পাতা থেকে জোড়া বসানো যায়।
     *
     * @return list<array<string, mixed>>
     */
    private function peopleRows(): array
    {
        $rows = [];

        $contracts = RentalContract::query()
            ->whereNotNull('party_type')
            ->whereNotNull('party_id')
            ->get();

        foreach ($contracts as $contract) {
            $key = $contract->party_type.':'.$contract->party_id;

            $rows[$key] ??= [
                'party_type' => (string) $contract->party_type,
                'party_id' => (int) $contract->party_id,
                'name' => $contract->counterparty,
                'contracts' => 0,
                'running' => 0,
                'rent' => '0',
                'deposit' => '0',
            ];

            $rows[$key]['contracts']++;

            if ($contract->isActive()) {
                $rows[$key]['running']++;
                $rows[$key]['rent'] = bcadd($rows[$key]['rent'], (string) $contract->monthly_rent, 4);
                $rows[$key]['deposit'] = bcadd($rows[$key]['deposit'], $contract->depositLeft(), 4);
            }
        }

        $out = array_values($rows);

        usort($out, fn (array $a, array $b) => [$b['running'], $a['name']] <=> [$a['running'], $b['name']]);

        return $out;
    }

    /**
     * কার সাথে চুক্তি — ব্যক্তি, গ্রাহক বা সরবরাহকারী।
     *
     * ⓘ তালিকাটা কোরের [[PartyRegistry]] থেকে, কারণ অর্থ গ্রাহক বা
     * সরবরাহকারী মডিউলের উপর নির্ভর করে না ([[BoundariesTest]])।
     * ⚠️ কর্মচারী বাদ: কর্মচারীর কাছ থেকে ঘর ভাড়া নেওয়া হলে তিনি
     * সেখানে বাড়িওয়ালা, কর্মচারী নয় — আর তাঁর নামটা ব্যক্তির তালিকাতেই।
     *
     * @return array<string, string>
     */
    private function parties(): array
    {
        $out = [];

        foreach (app(PartyRegistry::class)->forPicker() as $group) {
            if (! in_array($group['type'], ['person', 'customer', 'supplier'], true)) {
                continue;
            }

            foreach ($group['options'] as $option) {
                $out[$group['type'].':'.$option['id']] = $group['label'].' — '.$option['label'];
            }
        }

        return $out;
    }

    /**
     * বাছাই করা দুইটা ঘর → চারটা কলাম।
     *
     * ── ⭐ মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────────
     * *"কার সাথে * কীসের জন্য etar list kothay pabo?"* — দুইটাই এখন
     * তালিকা থেকে আসে। ⓘ পর্দায় একটা ঘর ("person:3"), খাতায় দুইটা কলাম:
     * ধরন আর আইডি — হাতধারের হুবহু একই ছক।
     *
     * ⭐ নামটাও বসে যায়: তালিকা থেকে বাছলে `counterparty`-তে ঐ পক্ষের
     * নামই লেখা হয়। ⚠️ নাহলে পুরনো তালিকা আর রিপোর্টগুলো, যেগুলো
     * টাইপ করা নামটা পড়ে, খালি ঘর দেখাত।
     *
     * @param  array<string, mixed>  $data
     */
    private function splitThePicks(array &$data): void
    {
        $parties = app(PartyRegistry::class);

        if (filled($data['party'] ?? null)) {
            [$type, $id] = array_pad(explode(':', (string) $data['party'], 2), 2, null);

            if ($parties->knows((string) $type) && (int) $id > 0
                && $parties->exists((string) $type, (int) $id)) {
                $data['party_type'] = $type;
                $data['party_id'] = (int) $id;

                if (blank($data['counterparty'] ?? null)) {
                    $data['counterparty'] = $parties->labelsOf([[$type, (int) $id]])[$type.':'.$id]
                        ?? (string) $type;
                }
            }
        }

        if (filled($data['subject_pick'] ?? null)) {
            [$type, $id] = array_pad(explode(':', (string) $data['subject_pick'], 2), 2, null);

            $subjects = app(RentalSubjects::class);

            if ($subjects->knows((string) $type) && (int) $id > 0) {
                $seen = $subjects->describe((string) $type, (int) $id);

                if ($seen['label'] !== null) {
                    $data['subject_type'] = $type;
                    $data['subject_id'] = (int) $id;

                    // ⓘ লেখার ঘরটা খালি থাকলে জিনিসটার নামই বসে
                    if (blank($data['subject'] ?? null)) {
                        $data['subject'] = $seen['label'];
                    }
                }
            }
        }

        unset($data['party'], $data['subject_pick']);
    }

    /** @return Collection<int, Account> */
    private function moneyAccounts()
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->get();
    }

    /**
     * ফর্মের সাথে আসা কাগজটা — সারিটা বসার **পরেই**।
     *
     * ⓘ কাগজ বসে `(উৎস, আইডি)` জোড়ার উপর, আর সারিটা তৈরি হওয়ার আগে
     * আইডিটাই নেই। ⛔ কাগজ আটকালে সারিটা থাকে, কেবল সতর্কবার্তা যায়।
     */
    private function keepThePaper(Request $request, RentalContract $row): void
    {
        if (! $request->hasFile('paper')) {
            return;
        }

        try {
            $this->attachments->store(
                file: $request->file('paper'),
                module: 'finance',
                entity: RentalContract::drillSourceType(),
                entityId: (int) $row->getKey(),
            );
        } catch (AttachmentException $refused) {
            session()->flash('warning', __('core.attachment.refused', [
                'reason' => $refused->getMessage(),
            ]));
        }
    }
}
