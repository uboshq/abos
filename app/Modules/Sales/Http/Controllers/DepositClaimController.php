<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ডিপোর দিক — ডিলারদের তোলা দাবিগুলো।
 *
 * তালিকাটা ডিফল্টে কেবল অপেক্ষমাণ দেখায়, কারণ এই পাতাটার একটাই কাজ:
 * আজ কোন দাবিগুলো যাচাই করা বাকি। গৃহীত ও প্রত্যাখ্যাত দাবি ইতিহাস,
 * আর ইতিহাস রোজ চোখের সামনে থাকলে আজকের কাজটা হারিয়ে যায়।
 */
class DepositClaimController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly DepositClaimService $claims,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.claim.view', only: ['index']),
            new Middleware('can:sales.claim.decide', only: ['accept', 'reject']),
        ];
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', DepositClaim::PENDING);

        return view('sales::claim.index', [
            'menu' => $this->menu->forUser($request->user()),
            'claims' => DepositClaim::query()
                ->with(['customer', 'bankAccount', 'decider'])
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderByDesc('claimed_on')->orderByDesc('id')
                ->paginate(50)->withQueryString(),
            'status' => $status,
            'pendingCount' => DepositClaim::query()->pending()->count(),
            'moneyAccounts' => Account::query()
                ->where(fn ($q) => $q->where('is_cash', true)->orWhere('is_bank', true))
                ->postable()->active()->orderBy('code')->get(),
        ]);
    }

    public function accept(Request $request, DepositClaim $claim): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],

            /*
             * অঙ্ক ও তারিখ সংশোধনযোগ্য।
             *
             * ডিলার ৫০,০০০ লিখেছেন, ব্যাংকে এসেছে ৪৯,৯৫০ (চার্জ কাটা)।
             * খাতায় বসবে যা সত্যিই এসেছে। দাবির সারিটা অক্ষত থাকে,
             * তাই তফাতটাও পরে দেখা যায়।
             */
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $this->claims->accept($claim, (int) $data['account_id'], [
            'amount' => $data['amount'] ?? null,
            'trx_date' => $data['trx_date'] ?? null,
        ]);

        return back()->with('status', __('sales::portal.accepted'));
    }

    public function reject(Request $request, DepositClaim $claim): RedirectResponse
    {
        $data = $request->validate([
            'decision_reason' => ['required', 'string', 'max:500'],
        ]);

        $this->claims->reject($claim, $data['decision_reason']);

        return back()->with('status', __('sales::portal.rejected'));
    }
}
