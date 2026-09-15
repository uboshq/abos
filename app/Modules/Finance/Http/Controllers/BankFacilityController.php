<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
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
            new Middleware('can:finance.bank_facility.create', only: ['store']),
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
        return view('finance::bank-facility.index', [
            'menu' => $this->menu->forUser($request->user()),
            'facilities' => BankFacility::query()->latest('id')->get(),
            'renewals' => $this->facilities->dueForRenewal(),
            'liabilityAccounts' => $this->liabilityAccounts(),
            'moneyAccounts' => $this->moneyAccounts(),
        ]);
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
            'bank' => ['required', 'string', 'max:191'],
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
            'paper' => ['nullable', 'file'],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'instalments' => ['nullable', 'integer', 'min:1', 'max:600'],
            'instalment_amount' => ['nullable', 'numeric', 'min:0'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'charges' => ['nullable', 'numeric', 'min:0'],

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

        $this->keepThePaper($request, $facility);

        return redirect()->route('finance.bank_facility.show', $facility)
            ->with('saved', __('finance::message.facility_opened'));
    }

    public function show(Request $request, BankFacility $bankFacility): View
    {
        return view('finance::bank-facility.show', [
            'menu' => $this->menu->forUser($request->user()),
            'facility' => $bankFacility->load(['liabilityAccount', 'moneyAccount']),
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
