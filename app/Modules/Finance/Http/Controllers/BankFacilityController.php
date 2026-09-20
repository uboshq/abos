<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Services\BankFacilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ব্যাংকের সুবিধা — মঞ্জুরি, জামানত, নবায়ন।
 *
 * ── ⛔ যা এই পর্দা করে না: টাকা নাড়া ─────────────────────────────────
 * একটাও দাখিলা এখান থেকে যায় না। **খাতা ঘটনা লেখে · ভাউচার টাকা নাড়ে।**
 * ⓘ মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬ — টাকা গ্রহণ ও পরিশোধ করে
 * একাই রসিদ ও পরিশোধের পর্দা।
 */
class BankFacilityController extends Controller implements HasMiddleware
{
    /*
     * ⛔ কোড তিনটা এখানে ধ্রুবক, কারণ ছকের মালিক `Accounts/`।
     *
     * ⚠️ কোডটা বদলালে এই ছাঁকনি **নীরবে খালি** হয়ে যাবে — কোনো ভুল
     * উঠবে না, শুধু ড্রপডাউনে কিছু থাকবে না। ⓘ তাই নামগুলো এক
     * জায়গায়, আর টেস্টে গুনে দেখা হয় ওরা সত্যিই আছে কি না।
     */
    private const TERM_LOAN = '2211';

    private const LEASE_LIABILITY = '2212';

    private const LTR_LIABILITY = '2170';

    public function __construct(
        private readonly BankFacilityService $facilities,
        private readonly MenuBuilder $menu,
        private readonly AttachmentEngine $attachments,
    ) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.bank_facility.view', only: ['index', 'show']),
            new Middleware('can:finance.bank_facility.create', only: ['create', 'store']),
            new Middleware('can:finance.bank_facility.close', only: ['close']),
        ];
    }

    /**
     * সব সুবিধা এক পর্দায়, আর উপরে যেগুলো ফুরাতে চলেছে।
     *
     * ── ⭐ কেন নবায়নের তালিকাটা উপরে, আলাদা করে ─────────────────────
     * ⛔ CC ও LTR বার্ষিক নবায়ন হয়, আর তারিখটা কেউ না দেখলে সুবিধাটা
     * **নীরবে ফুরায়**। ⚠️ টের পাওয়া যায় একটা চেক ফেরত এলে — সাধারণত
     * সরবরাহকারীর সামনে।
     *
     * ⓘ তালিকার ভিতরে মিশিয়ে দিলে ঐ সারিটা আর দশটার মতোই দেখাত।
     */
    public function index(Request $request): View
    {
        /*
         * ⭐ ট্যাব আর খোঁজা — তালিকা এখন টুলবারের নিচে, মালিকের নির্দেশে।
         * ১৯ সেপ্টেম্বর ২০২৬ — *"সব পাতাতেই সমস্যা"*।
         *
         * ⓘ চালু · বন্ধ দুই ট্যাব, মূলধনের পাতার মতো। ⚠️ "চালু" মানে
         * `closed` নয় এমন সব — অন্য কোনো অবস্থার সারি দুই ট্যাবের
         * কোনোটা থেকেই হারায় না।
         */
        $tab = $request->query('tab') === 'closed' ? 'closed' : 'active';

        $term = trim((string) $request->query('q'));

        $facilities = $this->facilityList($tab, $term);

        return view('finance::bank-facility.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,

            // ⭐ ব্যবহৃত অঙ্ক ও বাকি সীমা — খতিয়ান থেকে (অর্থের মানচিত্র §১৪গ, ২০ সেপ্টেম্বর ২০২৬)
            'standing' => $this->standingOf($facilities),

            // ⓘ ট্যাবের পাশের গোনা — খোঁজায় ছাঁকা হয় না, ট্যাবের মোট সংখ্যা
            'counts' => [
                'active' => BankFacility::query()->where('status', '!=', DocumentStatus::CLOSED)->count(),
                'closed' => BankFacility::query()->where('status', DocumentStatus::CLOSED)->count(),
            ],
            /*
             * ⭐ পাতা ভাগ — ১৭ সেপ্টেম্বর ২০২৬, নিরীক্ষার ধাপ ২।
             *
             * ⓘ সুবিধাগুলো **জমতেই থাকে**: প্রতিটা নবায়ন একটা নতুন সারি,
             * আর পুরনোগুলো ইতিহাস হিসেবে থেকে যায় (মুছলে ঐ সময়ের চেকের
             * হিসাব অনাথ হত)। ⚠️ তাই তালিকাটার কোনো স্বাভাবিক সীমা নেই।
             *
             * ⛔ সীমা ছাড়া তালিকা শেয়ার্ড হোস্টিংয়ে একদিন টাইমআউট করে —
             * আর সেদিন ব্যবহারকারী কেবল একটা সাদা পাতা দেখেন, কোনো
             * কারণ ছাড়াই।
             */
            'facilities' => $facilities,
            'renewals' => $this->facilities->dueForRenewal(),
        ]);
    }

    /**
     * তালিকার কোয়েরি — ট্যাব ও খোঁজা ধরে।
     *
     * ⓘ আলাদা মেথডে, কারণ সারিগুলো [[standingOf()]]-এরও লাগে, আর দুইবার
     * কোয়েরি চালানো মানে একদিন দুইটা আলাদা তালিকা।
     */
    private function facilityList(string $tab, string $term): LengthAwarePaginator
    {
        return BankFacility::query()
            ->when($tab === 'closed',
                fn ($q) => $q->where('status', DocumentStatus::CLOSED),
                fn ($q) => $q->where('status', '!=', DocumentStatus::CLOSED))
            /*
             * ⓘ খোঁজা — ব্যাংক, শাখা, মঞ্জুরির নম্বর, নথির নম্বর আর নোট।
             * ⚠️ মোড়কের `where(fn …)` জরুরি: নাহলে `orWhere` ট্যাবের
             * শর্তটাকে পাশ কাটিয়ে বন্ধ সারিও চালু ট্যাবে তুলে আনত।
             */
            ->when($term !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('bank', 'like', "%{$term}%")
                    ->orWhere('branch_name', 'like', "%{$term}%")
                    ->orWhere('sanction_no', 'like', "%{$term}%")
                    ->orWhere('document_no', 'like', "%{$term}%")
                    ->orWhere('note', 'like', "%{$term}%"),
            ))
            ->latest('id')->paginate(50)->withQueryString();
    }

    /**
     * ব্যবহৃত অঙ্ক ও বাকি সীমা — খতিয়ান থেকে (অর্থের মানচিত্র §১৪গ)।
     *
     * ⓘ হিসাবটা [[BankFacilityService::standing()]]-এ, আর সেখানেই লেখা
     * কেন সারিতে দ্বিতীয় কপি রাখা হয়নি।
     *
     * @return array<int, array{used: string, left: string}>
     */
    private function standingOf(LengthAwarePaginator $facilities): array
    {
        return $this->facilities->standing($facilities->getCollection());
    }

    /**
     * নতুন সুবিধার ফর্ম — নিজের পাতায় (১৯ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ আগে ফর্মটা তালিকার উপরে বসত, আর তালিকাটা লম্বা ফর্মের নিচে
     * চাপা পড়ত। ভুল হলে Laravel এই পাতাতেই ফেরায়, পুরনো লেখা সহ।
     */
    public function create(Request $request): View
    {
        return view('finance::bank-facility.create', [
            'menu' => $this->menu->forUser($request->user()),
            'institutions' => $this->institutions(),

            /*
             * ⭐ প্রতিষ্ঠানের শাখা — মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬:
             * *"ব্যাংক select korle শাখা auto asar kotha"*।
             *
             * ⓘ নামগুলো পাতার সাথেই যায়, কোনো fetch নয় — তালিকাটা ডিপোতে
             * দশে গোনা, আর CSP-Alpine-এ বাইরে ডাকার পথও নেই।
             */
            'branches' => Institution::query()
                ->whereIn('kind', [Institution::BANK, Institution::NBFI])
                ->active()
                ->pluck('branch_name', 'id')
                ->filter()
                ->all(),

            'liabilityAccounts' => $this->liabilityAccounts(),
            'moneyAccounts' => $this->moneyAccounts(),
        ]);
    }

    /**
     * বাছাইয়ের তালিকা — চালু ব্যাংক ও আর্থিক প্রতিষ্ঠান।
     *
     * ⓘ বীমা কোম্পানি বা মোবাইল ব্যাংকিং এখানে নয়: ঋণ বা আমানত ওদের
     * কাছে থাকে না, আর তালিকায় রাখলে ভুল বাছার পথ খুলে যেত।
     *
     * @return array<int, string>
     */
    private function institutions(): array
    {
        return Institution::query()
            ->whereIn('kind', [Institution::BANK, Institution::NBFI])
            ->active()
            ->orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (Institution $i) => [$i->id => $i->label()])
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        /*
         * ⚠️ ঘরগুলো এখানে কেবল **আকৃতি** মাপা হয় — সংখ্যা কি সংখ্যা,
         * তারিখ কি তারিখ। ⓘ *"এই ধরনে কোন ঘর লাগে"* সেই নিয়মটা
         * [[BankFacilityService::open()]]-এ, এক জায়গায়।
         *
         * ⛔ `required_if:kind,cc` লিখে এখানে ছড়িয়ে দিলে পাঁচটা ধরনের
         * চাহিদা পাঁচ জায়গায় থাকত, আর তুলনা করে দেখা যেত না।
         */
        $data = $request->validate([
            'kind' => ['required', Rule::in(BankFacility::KINDS)],
            'institution_id' => ['nullable', 'integer',
                Rule::exists('fin_institutions', 'id')->where('company_id', CompanyContext::id())],
            'institution_new' => ['nullable', 'string', 'max:160',
                'required_without:institution_id'],
            'branch_name' => ['nullable', 'string', 'max:191'],
            'sanction_no' => ['nullable', 'string', 'max:64'],
            'sanctioned_on' => ['required', 'date'],

            'limit_amount' => ['required', 'numeric', 'min:0'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'renews_on' => ['nullable', 'date'],

            'stock_value' => ['nullable', 'numeric', 'min:0'],

            /*
             * ⓘ স্টকের অঙ্কটা কবেকার — ড্রয়িং পাওয়ারের বয়স।
             * ⛔ ভবিষ্যতের তারিখ নেওয়া হয় না: স্টেটমেন্ট এখনো আসেনি
             * এমন তারিখ লিখলে সংখ্যাটা আরও টাটকা দেখাত, কম নয়।
             */
            'last_statement_on' => ['nullable', 'date', 'before_or_equal:today'],

            /*
             * ⚠️ `lte:limit_amount` — সীমার বেশি তোলা যায় না, আর
             * ⓘ ঐ ভুলটা টাইপ করতে গিয়ে সহজেই হয় (একটা শূন্য বেশি)।
             * ⛔ পাহারাটা না থাকলে ড্রয়িং পাওয়ার ঋণাত্মক হয়ে যেত, আর
             * পর্দা বলত ব্যবসাটা সীমার চেয়ে বেশি তুলে ফেলেছে।
             */
            'opening_drawn' => ['nullable', 'numeric', 'min:0', 'lte:limit_amount'],

            /*
             * ⭐ এই ঋণ কি নতুন, নাকি আগে থেকেই চলছে — ২০ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ নতুন হলে টাকা আসে রসিদ ভাউচারে, আগের মতো। ⚠️ আর আগে
             * থেকে চলতে থাকলে টাকাটা বছর আগেই এসেছিল — তখন কেবল
             * **আজকের বকেয়া** খাতায় তোলা হয়।
             */
            'already_running' => ['nullable', 'boolean'],
            'instalments_paid' => ['nullable', 'integer', 'min:0', 'max:600'],

            'paper' => ['nullable', 'file'],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'instalments' => ['nullable', 'integer', 'min:1', 'max:600'],
            'instalment_amount' => ['nullable', 'numeric', 'min:0'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'charges' => ['nullable', 'numeric', 'min:0'],

            /*
             * ⭐ মাঝপথে শোধের চার্জ — ২০ সেপ্টেম্বর ২০২৬।
             * ⓘ শতাংশ হলে ১০০-এর বেশি হতে পারে না; থোক হলে পারে,
             * তাই উপরের সীমাটা শতাংশের ক্ষেত্রেই।
             */
            'early_charge' => ['nullable', 'numeric', 'min:0'],
            'early_charge_kind' => ['nullable', Rule::in(BankFacility::CHARGE_KINDS)],
            'early_charge_basis' => ['nullable', Rule::in(BankFacility::CHARGE_BASES)],

            'security_type' => ['nullable', Rule::in(BankFacility::SECURITIES)],
            'security_value' => ['nullable', 'numeric', 'min:0'],
            'guarantors' => ['nullable', 'string', 'max:500'],
            'covenant' => ['nullable', 'string', 'max:500'],

            /*
             * ⚠️ `exists`-এ `company_id` — নাহলে অন্য কোম্পানির খাতের
             * আইডি বসিয়ে দিলে এখানকার দায় সেখানে গিয়ে বসত।
             */
            'liability_account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'money_account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],

            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $facility = $this->facilities->open($data);

        /*
         * ⭐ আগে থেকেই চলছে — তবে আজকের বকেয়াটা খাতায় তুলতে হয়।
         *
         * ⓘ নতুন ঋণে এটা চলে না: তখন টাকা আসে রসিদ ভাউচারে, আর
         * সেই পথটাই ব্যাংক হিসাব বাড়ায়। ⚠️ দুইটাই করলে টাকাটা
         * দুইবার আসত।
         */
        if ($request->boolean('already_running')) {
            $this->facilities->openingFor($facility, (string) ($data['opening_drawn'] ?? '0'));
        }

        $this->keepThePaper($request, $facility);

        return redirect()->route('finance.bank_facility.show', $facility)
            ->with('saved', __('finance::message.facility_opened'));
    }

    public function show(Request $request, BankFacility $bankFacility): View
    {
        return view('finance::bank-facility.show', [
            'menu' => $this->menu->forUser($request->user()),
            'facility' => $bankFacility->load(['liabilityAccount', 'moneyAccount']),

            /*
             * ⭐ কয়টা কিস্তি দেওয়া হলো, কয়টা বাকি — খাতা থেকে গোনা।
             * ⛔ কোনো গুনতি সংরক্ষণ করা হয় না (মালিকের নিয়ম) — সংরক্ষিত
             * সংখ্যা আর খাতা একদিন আলাদা কথা বলত।
             */
            'instalments' => $this->facilities->instalmentStanding($bankFacility),

            /*
             * ⓘ এখন কত বকেয়া — তালিকার পাতা যে হিসাবটা দেখায়,
             * হুবহু সেটাই ([[BankFacilityService::standing]])। ⚠️ দুই পর্দায়
             * দুই রকম হলে মানুষ কোনটা বিশ্বাস করবেন সেটাই বলতে পারতেন না।
             */
            'outstanding' => $this->facilities->standing(collect([$bankFacility]))[$bankFacility->id]['used']
                ?? '0',

            /* ⭐ আজ শোধ করলে কত — বকেয়া, চার্জ, মোট */
            'settlement' => $this->facilities->settlementToday($bankFacility),

            /* ⭐ কিস্তির তালিকা — মাস, আসল, সুদ, জের (মালিকের ছবি) */
            'schedule' => $this->facilities->schedule($bankFacility),
        ]);
    }

    public function close(Request $request, BankFacility $bankFacility): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->facilities->close($bankFacility, $data['note'] ?? null);

        return redirect()->route('finance.bank_facility.index')
            ->with('saved', __('finance::message.facility_closed'));
    }

    /**
     * দায়ের খাত বাছার তালিকা — ⭐ এখন ছেঁকে, ১৫ সেপ্টেম্বর ২০২৬।
     *
     * ── ⓘ আগে পুরো ছকই দেখানো হত, আর সেটা ইচ্ছাকৃত ছিল ───────────────
     * ঋণের নিজস্ব খাতগুলো (`2211` · `2212` · `2170`) তখনো চার্টে বসেনি,
     * আর ছেঁকে দিলে একটা **খালি ড্রপডাউন** পড়ত — ব্যবহারকারী বুঝতেন না
     * কী হারিয়ে গেছে। ⭐ খাতগুলো আজ বসেছে (abos-e8), তাই ছাঁকনিটাও।
     *
     * ── ⚠️ কেন CC ও BG-তে তালিকাটা খালি থাকে ────────────────────────
     * ⛔ ক্যাশ ক্রেডিটের দেনা আলাদা কোনো দায়ের খাতে বসে না — ওটা
     * **ব্যাংক হিসাবের নিজের ঋণাত্মক ব্যালান্স**। আর গ্যারান্টি কেউ
     * না ভাঙানো পর্যন্ত দায়ই নয়।
     *
     * ⓘ তাই ঐ দুই ধরনে ঘরটা খালি রাখাই সঠিক, আর
     * [[BankFacility::isBalanceSheetDebt()]] একই কথা বলে।
     *
     * @return Collection<int, Account>
     */
    private function liabilityAccounts(): Collection
    {
        return $this->postable()
            ->whereIn('code', [self::TERM_LOAN, self::LEASE_LIABILITY, self::LTR_LIABILITY])
            ->get(['id', 'code', 'name_en', 'name_bn']);
    }

    /**
     * টাকার খাত — নগদ, ব্যাংক, মোবাইল ব্যাংকিং।
     *
     * ⓘ `money()` স্কোপ `money_kind` ধরে বাছে, কোড ধরে নয় — তাই নতুন
     * ব্যাংক হিসাব খুললে সেটা নিজে থেকেই তালিকায় আসে।
     */
    private function moneyAccounts(): Collection
    {
        return $this->postable()->money()->get(['id', 'code', 'name_en', 'name_bn']);
    }

    /**
     * ⚠️ দুইটা শর্ত সব তালিকাতেই লাগে, তাই এক জায়গায়।
     *
     * ⓘ গ্রুপে দাখিলা বসে না, আর অন্য কোম্পানির খাত এখানে দেখানোই
     * উচিত নয় — `BelongsToCompany` স্কোপটা মডেলেই আছে।
     *
     * @return Builder<Account>
     */
    private function postable(): Builder
    {
        return Account::query()->where('is_group', false)->orderBy('code');
    }

    /**
     * ফর্মের সাথে আসা কাগজটা — সারিটা বসার **পরেই**।
     *
     * ⓘ কাগজ বসে `(উৎস, আইডি)` জোড়ার উপর, আর সারিটা তৈরি হওয়ার আগে
     * আইডিটাই নেই। ⛔ কাগজ আটকালে সারিটা থাকে, কেবল সতর্কবার্তা যায়।
     */
    private function keepThePaper(Request $request, BankFacility $row): void
    {
        if (! $request->hasFile('paper')) {
            return;
        }

        try {
            $this->attachments->store(
                file: $request->file('paper'),
                module: 'finance',
                entity: BankFacility::drillSourceType(),
                entityId: (int) $row->getKey(),
            );
        } catch (AttachmentException $refused) {
            session()->flash('warning', __('core.attachment.refused', [
                'reason' => $refused->getMessage(),
            ]));
        }
    }
}
