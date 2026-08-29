<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
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

    public function index(Request $request): View
    {
        return view('finance::hand-loan.index', [
            'menu' => $this->menu->forUser($request->user()),
            'standing' => $this->loans->standing(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'person_name' => ['required', 'string', 'max:160'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $account = $this->loans->open($data);

        /*
         * খোলার পর তার নিজের পাতায় — পরের কাজটা প্রায় সবসময় ওখানেই,
         * কারণ কেউ কেবল নাম লিখে রাখতে এই পর্দা খোলে না; টাকা দিতে
         * বা নিতে খোলে।
         */
        return redirect()->route('finance.hand_loan.show', $account)
            ->with('saved', __('finance::message.hand_loan_opened', ['who' => $account->person_name]));
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
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->loans->move($handLoan, $data);

        return back()->with('saved', __('finance::message.hand_loan_moved'));
    }

    public function settle(HandLoanAccount $handLoan): RedirectResponse
    {
        $this->loans->settle($handLoan);

        return back()->with('saved', __('finance::message.hand_loan_settled', [
            'who' => $handLoan->person_name,
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
