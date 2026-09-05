<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Core\Services\SettingsService;
use App\Modules\Customer\Models\Customer;
use App\Models\LedgerEntry;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\CustomerPapers;
use App\Modules\Sales\Services\DepositClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * গ্রাহকের নিজের পাতা।
 *
 * ── এখানে যা যা করা যায়, আর যা যায় না ────────────────────────────────
 * যায়: নিজের বকেয়া দেখা, নিজের বিলগুলো দেখা, আর একটা জমার দাবি তোলা।
 *
 * যায় না: আর সবকিছু। কোনো কিছু সম্পাদনা নয়, কোনো দাম দেখা নয়, অন্য
 * কারো কিছু নয়।
 *
 * ── প্রতিটা পদ্ধতি নিজের গ্রাহক নিজে বের করে ──────────────────────────
 * `$this->customer()` ছাড়া কোনো কোয়েরি হয় না, আর কোনো আইডি URL থেকে
 * নেওয়া হয় না। URL থেকে নিলে একদিন কেউ সংখ্যাটা বদলে অন্যের খাতা
 * দেখে ফেলতেন — আর ওটাই এই পুরো ফিচারটার একমাত্র সত্যিকারের ঝুঁকি।
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly DepositClaimService $claims,
        private readonly CustomerPapers $papers,
    ) {}

    /**
     * যিনি ঢুকেছেন।
     *
     * ⭐ উত্তরটা এখন [[CustomerPapers::customer]] থেকে, এখানে হাতে লেখা নয়।
     *
     * আগে একই কথা দুই জায়গায় ছিল — গার্ড থেকে ব্যবহারকারী, তারপর
     * `CompanyContext::set()`। **দুইটা কপি মানে একদিন একটা কপি বদলাবে
     * আর অন্যটা বদলাবে না**; সেদিন কোনো ত্রুটি আসত না, শুধু দুইটা
     * পাতা দুই রকম আচরণ করত।
     */
    private function customer(): Customer
    {
        return $this->papers->customer();
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
         * প্রার্থীরা — কোড ধরে, ইমেইল নয়।
         *
         * গ্রাহকের ইমেইল প্রায়ই থাকে না, আর থাকলেও ভাগাভাগি করা।
         * কোডটা (CUS-0007) বিলের উপরে ছাপা থাকে, তাই গ্রাহকের হাতেই
         * থাকে।
         *
         * ── কেন `first()` নয়, `get()` ───────────────────────────────
         * কোডটা কোম্পানির **ভেতরে** অনন্য, সবার মধ্যে নয়। দুইটা
         * কোম্পানিরই CUS-0001 থাকতে পারে, আর `first()` তখন যেকোনো
         * একটা তুলে আনত — অর্থাৎ দ্বিতীয় কোম্পানির গ্রাহক সঠিক
         * পাসওয়ার্ড দিয়েও ঢুকতে পারতেন না, কারণ যাচাইটা হত অন্য
         * কারো hash-এর সাথে।
         */
        $candidates = Customer::query()
            ->withoutGlobalScopes()
            ->where('code', $data['code'])
            ->where('portal_enabled', true)
            ->get();

        /*
         * `attempt()` নয়, নিজে মিলিয়ে দেখা — আর কারণটা তিক্ত।
         *
         * ── কী ভাঙা ছিল ─────────────────────────────────────────────
         * `Auth::guard('portal')->attempt()` ভেতরে `Customer::query()`
         * চালায় — গ্লোবাল স্কোপসহ। এটা অতিথির অনুরোধ, তাই তখনো কোনো
         * কোম্পানি বসানো নেই, আর `BelongsToCompany` ঠিক কাজটাই করত:
         * ব্যতিক্রম ছুঁড়ত। ফলে **প্রতিটা** গ্রাহক লগইনে ৫০০ আসত।
         *
         * টেস্টে ধরা পড়েনি, আর সেটাই সবচেয়ে জরুরি অংশ: টেস্টের
         * `setUp()`-এ `CompanyContext::set()` ডাকা হয়, আর ওটা স্ট্যাটিক
         * — তাই টেস্টের ভেতরের প্রতিটা অনুরোধে প্রসঙ্গটা বসানোই থাকত।
         * আসল ব্রাউজারে কখনো থাকে না। পাহারাটা পাশ করত ঠিক যে
         * পরিস্থিতিতে ফিচারটা কাজই করত না।
         *
         * ধরা পড়েছে ব্রাউজারে, লাইভে দেওয়ার আগে।
         */
        $customer = $candidates->first(fn (Customer $c) => Hash::check(
            $data['password'], (string) $c->portal_password,
        ));

        if ($customer === null) {
            /*
             * একটাই বার্তা, দুইটা ভুলের জন্য।
             *
             * "এই কোড নেই" আর "পাসওয়ার্ড ভুল" আলাদা করে বললে বাইরের
             * কেউ কোড ধরে ধরে বলে দিতে পারত কোন গ্রাহকদের পোর্টাল
             * চালু আছে।
             */
            throw ValidationException::withMessages([
                'code' => __('sales::portal.bad_login'),
            ]);
        }

        /*
         * প্রসঙ্গটা গ্রাহকের নিজের সারি থেকে, লগইন করানোর **আগে**।
         *
         * `login()` ব্যবহারকারীকে সেশনে বসায় আর ইভেন্ট ছাড়ে; ওই
         * ইভেন্টের শ্রোতা কেউ টেন্যান্ট ডাটা ছুঁলে প্রসঙ্গ ছাড়া আবার
         * সেই একই ব্যতিক্রম আসত।
         */
        CompanyContext::set($customer->company_id, $customer->branch_id);

        Auth::guard('portal')->login($customer);
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
        $customer = $this->customer();

        return view('sales::portal.home', [
            'customer' => $customer,
            'due' => $customer->outstanding(),
            'invoices' => $this->papers->invoices(20),
            'claims' => $this->claims->forCustomer($customer),
        ]);
    }

    /**
     * গ্রাহকের নিজের খতিয়ান — "আমার কত বাকি" প্রশ্নের পূর্ণ উত্তর।
     *
     * ── কেন এই পাতাটা সবার আগে ───────────────────────────────────────
     * মালিকের যুক্তি: গ্রাহক রোজ ফোন করে তিনটা জিনিস জিজ্ঞেস করেন, আর
     * এটাই এক নম্বর। হোম পাতায় নিট সংখ্যাটা আছে, কিন্তু **"কেন এত"**
     * প্রশ্নের উত্তর নেই — আর ওই প্রশ্নটাই ফোনটা করায়।
     *
     * ── কেন রিপোর্ট ইঞ্জিনের ledger রিপোর্ট ব্যবহার করা হয়নি ──────────
     * ওটা কোম্পানি ও শাখা ধরে চলে, পার্টি ধরে নয় — আর এখানে **পার্টিই
     * একমাত্র সীমা**। ওটাকে বাঁকানোর চেয়ে `scopeForParty()` সরাসরি
     * ডাকা সৎ, আর স্কোপটা তখন এক লাইনে পড়া যায়।
     */
    public function ledger(Request $request): View
    {
        $customer = $this->customer();

        [$from, $to] = $this->range($request);

        /*
         * ⭐ কোয়েরিগুলো এখন [[CustomerPapers]]-এ, এখানে নয়।
         *
         * আগে এই পাতাটা নিজে শাখার ছাঁকনি সরাত আর নিজে পার্টি মেলাত —
         * হোম পাতাটাও তাই করত, আলাদা করে। **একই কথা দুই জায়গায় হাতে
         * লেখা**, আর প্রতিটা নতুন পাতায় আরেকবার লিখতে হত।
         *
         * ⚠️ সেবাটার কোনো পদ্ধতি "কার" জিজ্ঞেস করে না — গ্রাহক আসে
         * গার্ড থেকে। তাই এখান থেকে ভুল আইডি পাঠানোর কোনো উপায়ই নেই।
         */
        $opening = $this->papers->openingBefore($from);
        $rows = $this->papers->ledgerBetween($from, $to);

        return view('sales::portal.ledger', [
            'customer' => $customer,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $this->runningBalance($rows, $opening),

            /*
             * ⚠️ সীমাটা কেবল তখনই, যখন কোম্পানি সুইচটা চালু রেখেছে।
             *
             * বন্ধ থাকলে "০" দেখানো যাবে না: গ্রাহক পড়তেন **তাঁর সীমা
             * শেষ**, তারপর ফোন করতেন — অর্থাৎ এই পোর্টালের গোটা
             * উদ্দেশ্যের উল্টো। আর মালিকের নিয়ম অনুযায়ী সীমা ০ মানে
             * "বাকিতে নয়", "মাল নয়" নয় — তাই লেখাটা "নগদ/অগ্রিম"।
             */
            'creditLimitOn' => app(SettingsService::class)->enabled('customer.credit_limit_enabled'),
        ]);
    }

    /**
     * প্রতিটা সারিতে চলমান ব্যালান্স বসিয়ে শেষেরটা ফেরত।
     *
     * ⓘ সংখ্যাগুলো `bcadd`/`bcsub` দিয়ে, float দিয়ে নয় — টাকার হিসাবে
     * float ব্যবহার করলে হাজার সারির পর পয়সা হারায়, আর এই রিপোর নিয়মই
     * তাই।
     *
     * @param  \Illuminate\Support\Collection<int, LedgerEntry>  $rows
     */
    private function runningBalance($rows, string $opening): string
    {
        $balance = $opening;

        foreach ($rows as $row) {
            $balance = bcsub(bcadd($balance, (string) $row->debit, 4), (string) $row->credit, 4);
            $row->running_balance = $balance;
        }

        return $balance;
    }

    /**
     * কোন সময়টা — না বললে চলতি অর্থবছর নয়, **সবটা**।
     *
     * ⓘ চেকলিস্ট বলে "Lifetime": গ্রাহক খতিয়ান খোলেন হিসাব মেলাতে, আর
     * তখন মাঝপথে কাটা একটা তালিকা কোনো কাজে আসে না। ছাঁকনি আছে, কিন্তু
     * ডিফল্ট নয়।
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        return [
            (string) $request->query('from', '1970-01-01'),
            (string) $request->query('to', now()->toDateString()),
        ];
    }

    public function showClaim(): View
    {
        $customer = $this->customer();

        return view('sales::portal.claim', [
            'customer' => $customer,
            'banks' => Account::query()->postable()
                ->where('is_bank', true)->active()->orderBy('code')->get(),
        ]);
    }

    public function storeClaim(Request $request): RedirectResponse
    {
        $customer = $this->customer();

        $data = $request->validate([
            'claimed_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:bank,mfs,cash'],
            'reference' => ['nullable', 'string', 'max:64'],
            'bank_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->claims->raise($customer, $data);

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
        $customer = $this->customer();

        abort_if($claim->customer_id !== $customer->id, 403);

        return view('sales::portal.claim-show', [
            'customer' => $customer,
            'claim' => $claim,
        ]);
    }
}
