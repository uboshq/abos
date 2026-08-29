<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\WithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * উত্তোলন — কে কত নিলেন, আর মাসে কতটা নিতে পারবেন।
 *
 * ── কেন সীমাটাও এই পর্দাতেই ──────────────────────────────────────────
 * সীমা পেরোলে সেবাটা আটকায়। বদলানোর ঘরটা অন্য পর্দায় থাকলে
 * ব্যবহারকারীকে আটকে গিয়ে খুঁজতে যেতে হত, আর বেশিরভাগ মানুষ খুঁজতে
 * যান না — তাঁরা ধরে নেন জিনিসটা নষ্ট।
 */
class WithdrawalController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly WithdrawalService $withdrawals,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.withdrawal.view', only: ['index']),
            new Middleware('can:finance.withdrawal.create', only: ['store']),
            new Middleware('can:finance.withdrawal.post', only: ['post']),
            new Middleware('can:finance.withdrawal.cap', only: ['cap']),
        ];
    }

    public function index(Request $request): View
    {
        $month = $request->query('month');

        return view('finance::withdrawal.index', [
            'menu' => $this->menu->forUser($request->user()),
            'month' => is_string($month) && $month !== '' ? $month : now()->format('Y-m'),
            'standing' => $this->withdrawals->standing(
                is_string($month) && $month !== '' ? $month.'-01' : null,
            ),
            'rows' => Withdrawal::query()->with(['moneyAccount', 'voucher'])
                ->orderByDesc('trx_date')->orderByDesc('id')->paginate(50),
            'accounts' => $this->moneyAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contributor_name' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'trx_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $withdrawal = $this->withdrawals->request($data);

        return back()->with('saved', __('finance::message.withdrawal_recorded', [
            'no' => $withdrawal->document_no,
        ]));
    }

    /**
     * টাকা গেল — খাতায় বসাও।
     *
     * কোন খাত থেকে সেটা এখানেই জিজ্ঞেস করা হয়, লেখার সময় নয়: তখনো
     * জানা ছিল না টাকাটা সিন্দুক থেকে যাবে না ব্যাংক থেকে।
     */
    public function post(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        $this->withdrawals->post(
            $withdrawal,
            Account::query()->findOrFail($data['money_account_id']),
        );

        return back()->with('saved', __('finance::message.withdrawal_posted', [
            'no' => $withdrawal->document_no,
        ]));
    }

    public function cap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contributor_name' => ['required', 'string', 'max:191'],
            'monthly_cap' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->withdrawals->setCap($data['contributor_name'], $data['monthly_cap'] ?? null);

        return back()->with('saved', __('finance::message.withdrawal_cap_set', [
            'who' => $data['contributor_name'],
        ]));
    }

    /** @return Collection<int, Account> */
    private function moneyAccounts(): Collection
    {
        return Account::query()
            ->where('is_group', false)
            ->whereIn('parent_id', Account::query()
                ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
            ->orderBy('code')->get();
    }
}
