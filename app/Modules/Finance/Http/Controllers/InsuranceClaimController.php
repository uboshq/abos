<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\InsuranceClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ বীমার দাবির খাতা — অর্থ-মডিউলের পরিকল্পনা ৬.৪, ৬ অক্টোবর ২০২৬ ([[InsuranceClaimService]])।
 *
 * ⓘ দেখা বীমা দেখার চাবিতে; জমা, অনুমোদন, টাকা আসা, বন্ধ, নাকচ — বীমা চালানোর চাবিতে। সব দাবির তালিকা রিপোর্টে
 * ([[InsuranceReports::CLAIMS]])।
 */
class InsuranceClaimController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly InsuranceClaimService $claims,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.insurance.view', only: ['show']),
            new Middleware('can:finance.insurance.manage', only: ['store', 'approve', 'receive', 'close', 'reject']),
        ];
    }

    public function show(Request $request, InsuranceClaim $claim): View
    {
        return view('finance::insurance.claim', [
            'menu' => $this->menu->forUser($request->user()),
            'claim' => $claim->load(['policy.institution', 'approvalVoucher', 'closeVoucher']),
            'receipts' => \App\Modules\Accounts\Models\Voucher::query()
                ->where('against_type', InsuranceClaim::drillSourceType())->where('against_id', $claim->id)
                ->orderBy('trx_date')->orderBy('id')->get(),
            'accounts' => app(VoucherService::class)->moneyAccounts(),
            'head' => $this->claims->expectedAccount($claim),
        ]);
    }

    public function store(Request $request, InsurancePolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'claim_no' => ['nullable', 'string', 'max:64'],
            'incident_on' => ['required', 'date', 'before_or_equal:today'],
            'claimed_on' => ['required', 'date', 'after_or_equal:incident_on', 'before_or_equal:today'],
            'incident' => ['required', 'string', 'max:500'],
            'claimed_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
        ]);

        $claim = $this->claims->lodge($policy, $data);

        return redirect()->route('finance.insurance.claim.show', $claim)
            ->with('saved', __('finance::insurance_claim.lodged'));
    }

    public function approve(Request $request, InsuranceClaim $claim): RedirectResponse
    {
        $data = $request->validate([
            'approved_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'approved_on' => ['required', 'date', 'before_or_equal:today'],
            'approval_ref' => ['required', 'string', 'max:100'],
        ]);

        $this->claims->approve($claim, $data);

        return back()->with('saved', __('finance::insurance_claim.approved_saved'));
    }

    public function receive(Request $request, InsuranceClaim $claim): RedirectResponse
    {
        $data = $request->validate([
            'money_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'instrument_no' => ['nullable', 'string', 'max:64'],
        ]);

        $done = $this->claims->receive($claim, $data);

        return back()->with($done['held'] ? 'warning' : 'saved', __($done['held']
            ? 'finance::insurance_claim.receipt_held'
            : 'finance::insurance_claim.receipt_saved', ['no' => $done['voucher']->document_no]));
    }

    public function close(Request $request, InsuranceClaim $claim): RedirectResponse
    {
        $data = $this->closing($request);
        $this->claims->close($claim, $data['close_note'], $data['closed_on']);

        return back()->with('saved', __('finance::insurance_claim.closed_saved'));
    }

    public function reject(Request $request, InsuranceClaim $claim): RedirectResponse
    {
        $data = $this->closing($request);
        $this->claims->reject($claim, $data['close_note'], $data['closed_on']);

        return back()->with('saved', __('finance::insurance_claim.rejected_saved'));
    }

    /** @return array{close_note: string, closed_on: string} */
    private function closing(Request $request): array
    {
        return $request->validate([
            'close_note' => ['required', 'string', 'max:500'],
            'closed_on' => ['required', 'date', 'before_or_equal:today'],
        ]);
    }
}
