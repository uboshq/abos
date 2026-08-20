<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DepositClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ডিলারের নিজের পাতা।
 *
 * ── এখানে যা যা করা যায়, আর যা যায় না ────────────────────────────────
 * যায়: নিজের বকেয়া দেখা, নিজের বিলগুলো দেখা, আর একটা জমার দাবি তোলা।
 *
 * যায় না: আর সবকিছু। কোনো কিছু সম্পাদনা নয়, কোনো দাম দেখা নয়, অন্য
 * কারো কিছু নয়।
 *
 * ── প্রতিটা পদ্ধতি নিজের ডিলার নিজে বের করে ──────────────────────────
 * `$this->dealer()` ছাড়া কোনো কোয়েরি হয় না, আর কোনো আইডি URL থেকে
 * নেওয়া হয় না। URL থেকে নিলে একদিন কেউ সংখ্যাটা বদলে অন্যের খাতা
 * দেখে ফেলতেন — আর ওটাই এই পুরো ফিচারটার একমাত্র সত্যিকারের ঝুঁকি।
 */
class PortalController extends Controller
{
    public function __construct(private readonly DepositClaimService $claims) {}

    /** যিনি ঢুকেছেন। */
    private function dealer(): Customer
    {
        $customer = Auth::guard('portal')->user();

        abort_if($customer === null, 403);

        /*
         * কোম্পানির প্রসঙ্গটা ডিলারের নিজের সারি থেকেই আসে।
         *
         * কর্মীর মতো "কোম্পানি বাছাই" বলে কিছু নেই — ডিলার একটাই
         * কোম্পানির। প্রসঙ্গ না বসালে `BelongsToCompany` ওয়েব
         * অনুরোধে ব্যতিক্রম ছুঁড়ত।
         */
        CompanyContext::set($customer->company_id, $customer->branch_id);

        return $customer;
    }

    public function showLogin(): View
    {
        return view('sales::portal.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ]);

        /*
         * কোড ধরে খোঁজা, ইমেইল নয়।
         *
         * ডিলারের ইমেইল প্রায়ই থাকে না, আর থাকলেও ভাগাভাগি করা।
         * কোডটা (CUS-0007) বিলের উপরে ছাপা থাকে, তাই ডিলারের হাতেই
         * থাকে — আর ওটা কোম্পানির ভেতরে অনন্য।
         */
        $customer = Customer::query()
            ->withoutGlobalScopes()
            ->where('code', $data['code'])
            ->where('portal_enabled', true)
            ->first();

        if ($customer === null || ! Auth::guard('portal')->attempt([
            'code' => $data['code'],
            'password' => $data['password'],
            'portal_enabled' => true,
        ])) {
            /*
             * একটাই বার্তা, দুইটা ভুলের জন্য।
             *
             * "এই কোড নেই" আর "পাসওয়ার্ড ভুল" আলাদা করে বললে বাইরের
             * কেউ কোড ধরে ধরে বলে দিতে পারত কোন ডিলারদের পোর্টাল
             * চালু আছে।
             */
            throw ValidationException::withMessages([
                'code' => __('sales::portal.bad_login'),
            ]);
        }

        $customer->forceFill(['portal_last_login_at' => now()])->saveQuietly();
        $request->session()->regenerate();

        return redirect()->route('sales.portal.home');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('sales.portal.login');
    }

    public function home(): View
    {
        $dealer = $this->dealer();

        return view('sales::portal.home', [
            'dealer' => $dealer,
            'due' => $dealer->outstanding(),
            'invoices' => SalesInvoice::query()
                ->withoutGlobalScope('user-branch')
                ->where('customer_id', $dealer->id)
                ->orderByDesc('trx_date')->orderByDesc('id')
                ->limit(20)->get(),
            'claims' => $this->claims->forCustomer($dealer),
        ]);
    }

    public function showClaim(): View
    {
        $dealer = $this->dealer();

        return view('sales::portal.claim', [
            'dealer' => $dealer,
            'banks' => Account::query()
                ->where('is_bank', true)->active()->orderBy('code')->get(),
        ]);
    }

    public function storeClaim(Request $request): RedirectResponse
    {
        $dealer = $this->dealer();

        $data = $request->validate([
            'claimed_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:bank,mfs,cash'],
            'reference' => ['nullable', 'string', 'max:64'],
            'bank_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->claims->raise($dealer, $data);

        return redirect()
            ->route('sales.portal.home')
            ->with('status', __('sales::portal.claim_raised'));
    }

    /**
     * নিজের একটা দাবি দেখা।
     *
     * বাঁধাই করা মডেলটা ব্যবহার করা হয় না — আইডিটা URL থেকে আসে, আর
     * তাই মালিকানা হাতে যাচাই করতে হয়। না করলে সংখ্যাটা বদলে অন্যের
     * দাবি দেখা যেত।
     */
    public function showOwnClaim(DepositClaim $claim): View
    {
        $dealer = $this->dealer();

        abort_if($claim->customer_id !== $dealer->id, 403);

        return view('sales::portal.claim-show', [
            'dealer' => $dealer,
            'claim' => $claim,
        ]);
    }
}
