<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
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
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.hand_loan.view', only: ['index', 'show']),
            new Middleware('can:finance.hand_loan.create', only: ['store']),
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
        return view('finance::hand-loan.index', [
            'menu' => $this->menu->forUser($request->user()),
            'standing' => $this->loans->standing(),
            'people' => Person::query()->active()->orderBy('name_en')
                ->pluck('name_en', 'id'),
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
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * ⓘ মোবাইলের ঘরটা আর এখানে নেই — নম্বরটা ব্যক্তির সারিতে বসে,
         * আর নতুন নাম লেখার সময় সেটাও একসাথেই নেওয়া হয়
         * ([[App\Modules\MasterData\Services\PersonResolver]])।
         */
        $data['person_id'] = $this->people->resolve($data);

        $account = $this->loans->open($data);

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
     * নগদ ও ব্যাংকের নিচের খাতগুলো।
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
}
