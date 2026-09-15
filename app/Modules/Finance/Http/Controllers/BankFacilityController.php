<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
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
    public function __construct(
        private readonly BankFacilityService $facilities,
        private readonly MenuBuilder $menu,
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
            'liabilityAccounts' => $this->accounts(),
            'moneyAccounts' => $this->accounts(),
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
     * খাত বাছার তালিকা।
     *
     * ⚠️ ১৬ সেপ্টেম্বর ২০২৬ পর্যন্ত ঋণের নিজস্ব খাতগুলো
     * (`2211` · `2212` · `2170`) চার্টে **বসানো হয়নি** — ওগুলো
     * `Accounts/`-এর কাজ, আর সেই সেশনকে জানানো আছে।
     *
     * ⓘ তাই এখানে পুরো চার্টই দেখানো হয়, ছেঁকে নয়: খাতটা না থাকলে
     * ছাঁকনি একটা **খালি ড্রপডাউন** দিত, আর ব্যবহারকারী বুঝতেন না
     * কী হারিয়ে গেছে। ⭐ খাতগুলো বসার পর এখানে ছাঁকনি বসবে।
     *
     * @return Collection<int, Account>
     */
    private function accounts(): Collection
    {
        return Account::query()
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_bn']);
    }
}
