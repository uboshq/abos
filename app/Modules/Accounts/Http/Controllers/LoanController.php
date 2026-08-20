<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ঋণ — টার্ম লোন ও CC।
 *
 * ── কেন খাত দিয়ে কাজ চলে না ─────────────────────────────────────────
 * ২২১০ ব্যাংক ঋণ আর ২২২০ অন্যান্য ঋণ — খাত দুইটা আগে থেকেই ছিল, তাই
 * ভাউচার দিয়ে টাকা জমা-খরচ লেখা যেত। কিন্তু খাত একটা যোগফল ছাড়া কিছু
 * জানে না। সে বলতে পারে না কিস্তি কবে, কতটা আসল আর কতটা সুদ, কত বাকি,
 * কিংবা সীমার আর কতটা খালি — অথচ ঋণ নিয়ে প্রতিটা প্রশ্ন ঠিক ওগুলোই।
 */
class LoanController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly LoanService $loans,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.loan.view', only: ['index', 'show']),
            new Middleware('can:accounts.loan.manage', only: [
                'create', 'store', 'drawDown', 'payInstalment', 'repay', 'chargeInterest',
            ]),
        ];
    }

    public function index(Request $request): View
    {
        $loans = Loan::query()->orderByDesc('id')->get();

        return view('accounts::loan.index', [
            'menu' => $this->menu->forUser($request->user()),
            'loans' => $loans,

            /*
             * মোট বকেয়া খতিয়ান থেকে গোনা, আলাদা কোনো কলাম থেকে নয়।
             *
             * দুই জায়গায় একই সংখ্যা রাখলে একদিন আলাদা হবেই — একটা
             * পরিশোধ দুইবার বসলে, বা কেউ ভাউচার দিয়ে সরাসরি ঋণের খাতে
             * হাত দিলে।
             */
            'total' => $loans->reduce(
                fn (string $sum, Loan $l) => bcadd($sum, $l->outstanding(), 4),
                '0',
            ),
        ]);
    }

    public function create(Request $request): View
    {
        /*
         * সুদের খাত আগে থেকেই বাছা — কিন্তু বদলানো যায়।
         *
         * ── কেন ডিফল্ট লাগল ─────────────────────────────────────────
         * ঘরটা বাধ্যতামূলক, আর তালিকায় ত্রিশটা খরচের খাত। প্রথম ঋণ
         * বসানোর সময় মানুষ যেটা চেনা মনে হয় সেটাই বেছে নেন — আর
         * "ব্যাংক চার্জ" নামটা কাছাকাছি শোনায় বলে সুদ ওখানেই গিয়ে বসত।
         * পরীক্ষায় ঠিক সেটাই হয়েছিল (HP, ১৩ আগস্ট)।
         *
         * দুইটা মিশে গেলে "ধার করতে বছরে কত খরচ হলো" প্রশ্নের উত্তর
         * দেওয়া যায় না, আর নিরীক্ষক সুদ আলাদা করে দেখতে চান।
         *
         * তবু ঘরটা খোলা: কেউ ব্যাংক-ঋণ ও ব্যক্তি-ঋণের সুদ আলাদা খাতে
         * রাখেন, আর সেটা তাঁদের সিদ্ধান্ত।
         */
        $loan = new Loan;
        $loan->interest_account_id = Account::query()
            ->where('code', StandardChart::INTEREST_EXPENSE)
            ->value('id');

        return view('accounts::loan.form', [
            'menu' => $this->menu->forUser($request->user()),
            'loan' => $loan,
            'principalAccounts' => $this->accountsUnder('2200'),
            'interestAccounts' => $this->postableAccounts(Account::EXPENSE),
            'moneyAccounts' => $this->moneyAccounts(),

            /*
             * যে ঋণগুলোর পেছনে একটা FD বাঁধা যেতে পারে।
             *
             * কেবল নেওয়া ঋণ: নিজের দেওয়া টাকার পেছনে নিজের FD বাঁধার
             * কোনো মানে নেই। আর FD বা DPS নিজেও তালিকায় আসে না —
             * নাহলে একটা FD আরেকটা FD-র পেছনে বাঁধা যেত, আর "টাকাটা
             * কোথায় আটকে" প্রশ্নের উত্তর একটা বৃত্ত হয়ে যেত।
             */
            'openLoans' => Loan::query()
                ->where('direction', Loan::TAKEN)
                ->whereIn('kind', [Loan::TERM, Loan::CC])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lender' => ['required', 'string', 'max:160'],
            'account_no' => ['nullable', 'string', 'max:64'],
            'kind' => ['required', Rule::in([Loan::TERM, Loan::CC, Loan::HAND, Loan::FD, Loan::DPS])],

            /*
             * দিক — কেবল হাতধারে অর্থবহ।
             *
             * ব্যাংক ঋণ সবসময় নেওয়া; কেউ ব্যাংককে ধার দেয় না। তাই
             * ঘরটা ফর্মে শুধু হাতধার বাছলে দেখা যায়, আর না এলে ডিফল্ট
             * `taken` — যেটা আজ পর্যন্ত সব সারির সত্যি।
             */
            'direction' => ['nullable', Rule::in([Loan::TAKEN, Loan::GIVEN])],

            /*
             * ফেরতের কথা দেওয়া তারিখ — হাতধারের একমাত্র সময়সীমা।
             *
             * কিস্তির সূচি নেই বলে দেরি ধরার আর কোনো উপায় নেই। খালি
             * রাখা যায়: কেউ তারিখ না বললে কথা ভাঙার প্রশ্নও ওঠে না।
             */
            'due_on' => ['nullable', 'date'],

            /*
             * মেয়াদ শেষের তারিখ — FD ও DPS-এ।
             *
             * `due_on` থেকে আলাদা: একটা প্রতিশ্রুতি, অন্যটা চুক্তি।
             * হাতধারের তারিখ পেরোলে দেরি; FD-র তারিখ পেরোলে প্রাপ্য।
             */
            'matures_on' => ['nullable', 'date'],

            /*
             * এই FD কোন ঋণের বিপরীতে বাঁধা।
             *
             * বাঁধা FD তালিকায় "আছে" দেখায়, অথচ ভাঙানো যায় না — আর
             * ওই টাকার উপর ভরসা করে নেওয়া সিদ্ধান্তই সবচেয়ে দামি ভুল।
             */
            'pledged_against_id' => ['nullable', 'integer', 'exists:acc_loans,id'],
            'sanctioned' => ['required', 'numeric', 'gt:0'],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['required', 'date'],
            'principal_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'interest_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'security' => ['nullable', 'string', 'max:500'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * টার্ম লোনেই কেবল মেয়াদ, পদ্ধতি ও প্রথম কিস্তির তারিখ।
             *
             * CC-তে কিস্তি বলে কিছু নেই, তাই ঘরগুলো ফর্মেই দেখা যায় না
             * — আর যাচাইও শর্তসাপেক্ষ, নইলে CC বসাতে গেলে এমন ঘর চাইত
             * যা পর্দায় নেই।
             */
            'interest_method' => ['nullable', Rule::in([LoanSchedule::REDUCING, LoanSchedule::FLAT])],
            'tenure_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'first_instalment_on' => ['nullable', 'date'],

            // টাকাটা কোথায় ঢুকল — টার্ম লোনে বাধ্যতামূলক
            'into_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        if ($data['kind'] === Loan::TERM) {
            $request->validate([
                'interest_method' => ['required'],
                'tenure_months' => ['required'],
                'into_account_id' => ['required'],
            ]);
        }

        /*
         * হাতধারেও টাকাটা কোথায় গেল বা কোথা থেকে এল সেটা লাগে।
         *
         * টার্ম লোনের মতোই পুরো টাকা একবারেই নড়ে, তাই ঘরটা ছাড়া
         * দাখিলাটাই বসত না — ধারটা খাতায় থাকত অথচ টাকাটা কোথাও নড়ত
         * না, আর নগদ মিলত না।
         */
        if (in_array($data['kind'], [Loan::HAND, Loan::FD], true)) {
            $request->validate(['into_account_id' => ['required']]);
        }

        $loan = $this->loans->create(
            data: [
                'lender' => $data['lender'],
                'account_no' => $data['account_no'] ?? null,
                'kind' => $data['kind'],
                /*
                 * দিকটা শুধু পাঠানো হয়; ঠিক করে LoanService।
                 *
                 * নিয়মটা এখানেও লিখলে এক নিয়ম দুই জায়গায় থাকত, আর
                 * দুই জায়গার নিয়ম একদিন আলাদা হয়ই।
                 */
                'direction' => $data['direction'] ?? null,
                'due_on' => $data['kind'] === Loan::HAND ? ($data['due_on'] ?? null) : null,
                'matures_on' => in_array($data['kind'], [Loan::FD, Loan::DPS], true)
                    ? ($data['matures_on'] ?? null)
                    : null,
                'pledged_against_id' => $data['kind'] === Loan::FD
                    ? ($data['pledged_against_id'] ?? null)
                    : null,
                'interest_method' => $data['kind'] === Loan::TERM ? $data['interest_method'] : null,
                'sanctioned' => $data['sanctioned'],
                'interest_rate' => $data['interest_rate'],
                'tenure_months' => $data['kind'] === Loan::TERM ? $data['tenure_months'] : null,
                'start_date' => $data['start_date'],
                'first_instalment_on' => $data['first_instalment_on'] ?? $data['start_date'],
                'principal_account_id' => $data['principal_account_id'],
                'interest_account_id' => $data['interest_account_id'],
                'security' => $data['security'] ?? null,
                'narration' => $data['narration'] ?? null,
            ],
            intoAccountId: in_array($data['kind'], [Loan::TERM, Loan::HAND, Loan::FD], true)
                ? (int) $data['into_account_id']
                : null,
        );

        return redirect()
            ->route('accounts.loan.show', $loan->id)
            ->with('saved', __('accounts::message.loan_created'));
    }

    public function show(Request $request, Loan $loan): View
    {
        return view('accounts::loan.show', [
            'menu' => $this->menu->forUser($request->user()),
            'loan' => $loan->load('instalments', 'movements.counterAccount', 'principalAccount', 'interestAccount'),
            'moneyAccounts' => $this->moneyAccounts(),
        ]);
    }

    /** CC-তে টাকা তোলা — সীমার ভেতরে। */
    public function drawDown(Request $request, Loan $loan): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'into_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'trx_date' => ['nullable', 'date'],
        ]);

        $this->loans->drawDown(
            $loan,
            (string) $data['amount'],
            (int) $data['into_account_id'],
            $data['trx_date'] ?? null,
        );

        return back()->with('saved', __('accounts::message.loan_drawn'));
    }

    /** একটা কিস্তি পরিশোধ — আসল দায় কমায়, সুদ খরচে যায়। */
    public function payInstalment(Request $request, Loan $loan, int|string $instalment): RedirectResponse
    {
        $row = LoanInstalment::query()
            ->where('loan_id', $loan->id)
            ->whereKey($instalment)
            ->firstOrFail();

        $data = $request->validate([
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'trx_date' => ['nullable', 'date'],

            /*
             * ব্যাংক কখনো সূচির চেয়ে কম-বেশি কাটে (জরিমানা, ছাড়)।
             *
             * খালি রাখলে সূচির অঙ্কই ধরা হয়। দিলে সুদটা সূচির মানেই
             * থাকে আর বাকিটা আসলে যায় — উল্টো করলে খরচের খাত ব্যাংকের
             * কাগজের সাথে মিলত না, আর সুদের অঙ্ক করের হিসাবেও যায়।
             */
            'amount' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $this->loans->payInstalment(
            $row,
            (int) $data['from_account_id'],
            $data['trx_date'] ?? null,
            isset($data['amount']) ? (string) $data['amount'] : null,
        );

        return back()->with('saved', __('accounts::message.instalment_paid'));
    }

    /** CC-তে জমা — কেবল দায় কমে। */
    public function repay(Request $request, Loan $loan): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'trx_date' => ['nullable', 'date'],
        ]);

        $this->loans->repay(
            $loan,
            (string) $data['amount'],
            (int) $data['from_account_id'],
            $data['trx_date'] ?? null,
        );

        return back()->with('saved', __('accounts::message.loan_repaid'));
    }

    /**
     * মাসের সুদ বসানো — CC-তে।
     *
     * অঙ্কটা ব্যাংকের বিবরণী থেকে হাতে লেখা হয়, নিজে গোনা হয় না।
     * প্রতিদিনের বকেয়ার উপর সুদ ব্যাংক তার নিজের নিয়মে গোনে (কোন দিন
     * থেকে কোন দিন, ছুটির দিন কীভাবে) — আমরা গুনলে দুইটা সংখ্যা কখনো
     * এক হত না, আর প্রতি মাসে কেউ মেলাতে বসত।
     *
     * LoanSchedule::interestOnDailyBalance আছে যাচাই করার জন্য: ব্যাংকের
     * অঙ্ক সন্দেহজনক হলে নিজে গুনে দেখা যায়।
     */
    public function chargeInterest(Request $request, Loan $loan): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'trx_date' => ['nullable', 'date'],
        ]);

        $this->loans->chargeInterest($loan, (string) $data['amount'], $data['trx_date'] ?? null);

        return back()->with('saved', __('accounts::message.interest_charged'));
    }

    /** একটা মাথার নিচের সব খাত — যেগুলোয় সত্যিই দাখিলা বসে। */
    private function accountsUnder(string $parentCode)
    {
        $parent = Account::query()->where('code', $parentCode)->first();

        return $parent === null
            ? collect()
            : Account::query()
                ->where('parent_id', $parent->id)
                ->where('is_group', false)
                ->orderBy('code')
                ->get();
    }

    private function postableAccounts(string $type)
    {
        return Account::query()
            ->where('type', $type)
            ->where('is_group', false)
            ->orderBy('code')
            ->get();
    }

    /** নগদ ও ব্যাংক — টাকাটা কোথায় ঢুকল বা কোথা থেকে গেল। */
    private function moneyAccounts()
    {
        $heads = Account::query()
            ->whereIn('code', [StandardChart::CASH_IN_HAND, StandardChart::BANK_AND_MFS])
            ->pluck('id');

        return Account::query()
            ->where(fn ($q) => $q->whereIn('parent_id', $heads)->orWhereIn('id', $heads))
            ->where('is_group', false)
            ->orderBy('code')
            ->get();
    }
}
