<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\ImportRunner;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Services\BankReconciliationService;
use App\Modules\Accounts\Services\BankStatementService;
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
        private readonly BankStatementService $statements,
        private readonly ImportRunner $imports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.reconciliation.view', only: ['index', 'show']),
            new Middleware('can:accounts.reconciliation.manage', only: ['create', 'store', 'mark', 'confirm', 'statement']),
            new Middleware('can:accounts.reconciliation.reopen', only: ['reopen']),
        ];
    }

    public function index(Request $request): View
    {
        return view('accounts::reconciliation.index', [
            'menu' => $this->menu->forUser($request->user()),
            'reconciliations' => BankReconciliation::query()
                ->with(['bankAccount', 'confirmer'])
                /*
                 * খোঁজা — ব্যাংক হিসাবের নাম/কোড/নম্বর, আর বিবরণ।
                 *
                 * ⓘ whereHas এখানে সস্তা: ব্যাংক খাত হাতে গোনা কয়েকটা, আর
                 * মিলকরণ মাসে একটা করে। তালিকার প্রথম কলামটাই ব্যাংকের নাম,
                 * তাই মানুষ ওটা দিয়েই খোঁজেন।
                 */
                ->when(trim((string) $request->query('q')) ?: null, fn ($query, $term) => $query->where(
                    fn ($w) => $w->where('narration', 'like', "%{$term}%")
                        ->orWhereHas('bankAccount', fn ($a) => $a->where('name_en', 'like', "%{$term}%")
                            ->orWhere('name_bn', 'like', "%{$term}%")
                            ->orWhere('code', 'like', "%{$term}%")
                            ->orWhere('account_number', 'like', "%{$term}%"))
                ))
                ->orderByDesc('statement_date')
                ->paginate(50)
                ->withQueryString(),
            'q' => $request->query('q'),
        ]);
    }

    /**
     * মিলকরণ খোলার পর্দা।
     *
     * ⭐ কেবল ব্যাংক খাতের তালিকা — মিলকরণের সারিগুলো এখানে তোলা হয়
     * না, কারণ এই পাতায় একটাও সারি দেখানো হয় না।
     *
     * ── ⚠️ তিনটা শর্ত, আর তিনটাই ইচ্ছাকৃত ────────────────────────────
     * `ofMoneyKind(BANK)` — ⛔ MFS নয়: বিকাশের অ্যাপের লগ কাগজের
     * ব্যাংক বিবরণী নয়, তাই ওটা মিলকরণের তালিকায় আসেই না।
     * ⓘ স্কোপটা নিজেই **দল** ছাঁকে, তাই আলাদা `postable()` লাগে না —
     * একটা দলে দাখিলা বসলে সেটা কোনো রিপোর্টে আসত না
     * ([[Account::balanceOn()]] দলের নিজের সারি গোনে না), অথচ খতিয়ানে
     * সারিটা থেকে যেত।
     *
     * ⓘ আগে এই কোয়েরিটা `index()`-এও ছিল, কারণ তৈরির ফর্মটা তালিকার
     * নিচে গোঁজা থাকত। ফর্মটা এই পাতায় সরে আসায় ওখানে আর লাগে না।
     */
    public function create(Request $request): View
    {
        return view('accounts::reconciliation.create', [
            'menu' => $this->menu->forUser($request->user()),
            'banks' => Account::query()->ofMoneyKind(Account::BANK)->active()->orderBy('code')->get(),
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

            /*
             * ⭐ পর্দার নতুন অর্ধেক: **ব্যাংক যা জানে, আমরা জানি না**।
             * ⓘ এতদিন কেবল উল্টো দিকটা দেখা যেত — আমাদের কোন সারি ব্যাংকে
             * ওঠেনি। ⚠️ অথচ মাস শেষে তফাত থেকে যাওয়ার আসল কারণ প্রায়ই
             * এই দিকটাই: চার্জ, সুদ, ফেরত আসা চেক।
             */
            'fromBank' => $this->statements->unmatchedFor(
                $reconciliation->bankAccount,
                $reconciliation->statement_date->toDateString(),
            ),
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

    /**
     * ⭐ ব্যাংকের স্টেটমেন্ট তোলা — মানচিত্র §৯।
     *
     * ── ⚠️ কেন সাধারণ ইমপোর্টের পর্দা দিয়ে নয় ──────────────────────
     * ওখানে যেতে লাগে `system_admin.import.manage` — নতুন কোম্পানি বসানোর
     * ক্ষমতা। ⛔ কিন্তু স্টেটমেন্ট তোলা মাসের রোজকার কাজ, আর সেটা করেন
     * হিসাবরক্ষক। ⓘ তাই দরজাটা এখানে, `accounts.reconciliation.manage`-এর
     * পিছনে; কিন্তু ফাইল পড়া, যাচাই আর ভুল-সারির হিসাব সবই কাঠামোরই
     * ([[ImportRunner]]) — দ্বিতীয় একটা পাঠক লেখা হয়নি।
     *
     * ⓘ তোলার পরপরই নিজে থেকে মেলানোর চেষ্টা হয়, আর যা মেলে না সেটাই
     * পর্দায় থাকে — ওটাই আসল প্রশ্ন।
     */
    public function statement(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        $request->validate([
            /*
             * ⚠️ `csv,txt` দুইটাই — উইন্ডোজের এক্সেল CSV ফাইলকে
             * `text/plain` বলে পাঠায়, আর কেবল csv লিখলে ব্যবহারকারীর
             * নিজের ফাইলটাই ফিরিয়ে দেওয়া হত (সাধারণ ইমপোর্টের পর্দায়
             * এই ভুলটা একবার হয়েছিল)।
             */
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $result = $this->imports->run('bank_statement', $request->file('file'));

        $matched = $this->statements->matchAgainstBooks(
            $reconciliation->bankAccount,
            $reconciliation->statement_date->toDateString(),
        );

        return back()->with('status', __('accounts::recon.statement_loaded', [
            'rows' => $result['imported'],
            'matched' => $matched,
            'bad' => count($result['failed']),
        ]));
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
