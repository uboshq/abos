<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Services\BankReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ব্যাংক মিলকরণ।
 *
 * ── দুইটা পর্দা, আর কেন দুইটাই লাগে ──────────────────────────────────
 * তালিকা বলে কোন হিসাবের কোন মাস মেলানো হয়েছে আর কোনটা বাকি — মাস
 * শেষে এটাই প্রথম প্রশ্ন। আর ভেতরের পর্দাটা কাজের জায়গা: লাইন ধরে টিক,
 * আর উপরে তফাতের অঙ্কটা সবসময় চোখের সামনে।
 *
 * চেক রেজিস্টারের মতো এক পাতায় সব আঁটানো যেত না — ওখানে সিদ্ধান্ত
 * সারিপ্রতি, এখানে সিদ্ধান্ত পুরো কাগজটা নিয়ে।
 */
class BankReconciliationController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly BankReconciliationService $recons,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.reconciliation.view', only: ['index', 'show']),
            new Middleware('can:accounts.reconciliation.manage', only: ['store', 'mark', 'confirm']),
            new Middleware('can:accounts.reconciliation.reopen', only: ['reopen']),
        ];
    }

    public function index(Request $request): View
    {
        return view('accounts::reconciliation.index', [
            'menu' => $this->menu->forUser($request->user()),
            'reconciliations' => BankReconciliation::query()
                ->with(['bankAccount', 'confirmer'])
                ->orderByDesc('statement_date')
                ->paginate(50)
                ->withQueryString(),
            /*
             * ⚠️ `postable()` — দল বাদ, নাহলে দলে দাখিলা বসানো যেত।
             *
             * ⓘ একটা দলে টাকা বসলে সেটা **কোনো রিপোর্টে আসে না**
             * (`Account::balanceOn()` দলের নিজের সারি গোনে না), অথচ
             * খতিয়ানে সারিটা থাকে। বাকি খাত-নির্বাচকগুলো এটা ছাঁকত,
             * এই দুইটা (এখানে আর [[ChequeController]]) ভুলে গিয়েছিল।
             *
             * ⓘ `AccountRequest` দলকে `is_bank` হতে দেয় না, তাই তালিকাটা
             * এমনিতেই খালি থাকার কথা — **কিন্তু ওটা কেবল ফর্মের পথ**;
             * সিডার, ইমপোর্ট বা মাইগ্রেশন ওই যাচাই দিয়ে যায় না।
             */
            'banks' => Account::query()->where('is_bank', true)->postable()->active()->orderBy('code')->get(),
        ]);
    }

    public function show(Request $request, BankReconciliation $reconciliation): View
    {
        return view('accounts::reconciliation.show', [
            'menu' => $this->menu->forUser($request->user()),
            'recon' => $reconciliation->load('bankAccount', 'confirmer'),
            'lines' => $this->recons->candidates($reconciliation)
                ->sortBy([['voucher.trx_date', 'asc'], ['id', 'asc']])
                ->values(),
            'summary' => $this->recons->summary($reconciliation),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $recon = $this->recons->open($data);

        return redirect()
            ->route('accounts.reconciliation.show', $recon)
            ->with('status', __('accounts::recon.opened'));
    }

    public function mark(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['array'],
            'lines.*' => ['integer'],
        ]);

        $this->recons->mark($reconciliation, $data['lines'] ?? []);

        return back()->with('status', __('accounts::recon.marked'));
    }

    public function confirm(BankReconciliation $reconciliation): RedirectResponse
    {
        $this->recons->confirm($reconciliation);

        return back()->with('status', __('accounts::recon.confirmed'));
    }

    public function reopen(BankReconciliation $reconciliation): RedirectResponse
    {
        $this->recons->reopen($reconciliation);

        return back()->with('status', __('accounts::recon.reopened'));
    }
}
