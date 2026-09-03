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
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DepositClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
         * প্রার্থীরা — কোড ধরে, ইমেইল নয়।
         *
         * ডিলারের ইমেইল প্রায়ই থাকে না, আর থাকলেও ভাগাভাগি করা।
         * কোডটা (CUS-0007) বিলের উপরে ছাপা থাকে, তাই ডিলারের হাতেই
         * থাকে।
         *
         * ── কেন `first()` নয়, `get()` ───────────────────────────────
         * কোডটা কোম্পানির **ভেতরে** অনন্য, সবার মধ্যে নয়। দুইটা
         * কোম্পানিরই CUS-0001 থাকতে পারে, আর `first()` তখন যেকোনো
         * একটা তুলে আনত — অর্থাৎ দ্বিতীয় কোম্পানির ডিলার সঠিক
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
         * ব্যতিক্রম ছুঁড়ত। ফলে **প্রতিটা** ডিলার লগইনে ৫০০ আসত।
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
             * কেউ কোড ধরে ধরে বলে দিতে পারত কোন ডিলারদের পোর্টাল
             * চালু আছে।
             */
            throw ValidationException::withMessages([
                'code' => __('sales::portal.bad_login'),
            ]);
        }

        /*
         * প্রসঙ্গটা ডিলারের নিজের সারি থেকে, লগইন করানোর **আগে**।
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

    /**
     * ডিলারের নিজের খতিয়ান — "আমার কত বাকি" প্রশ্নের পূর্ণ উত্তর।
     *
     * ── কেন এই পাতাটা সবার আগে ───────────────────────────────────────
     * মালিকের যুক্তি: ডিলার রোজ ফোন করে তিনটা জিনিস জিজ্ঞেস করেন, আর
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
        $dealer = $this->dealer();

        [$from, $to] = $this->range($request);

        /*
         * ⚠️ `withoutGlobalScope('user-branch')` — ডিলারের কোনো শাখা নেই।
         *
         * ছাঁকনিটা কর্মীর জন্য বানানো ("আমি যে শাখায় বসি")। ডিলারের
         * বেলায় ওটা থাকলে তিনি **নিজের অর্ধেক কাগজ দেখতেন না**, আর
         * ব্যালান্স মিলত না — অথচ কোথাও কোনো ত্রুটি হত না।
         *
         * ⓘ উল্টো দিকে ভুল করলে অনেক খারাপ: `forParty` বাদ দিলে
         * **অন্য ডিলারের সারি** চলে আসত। তাই দুইটা শর্তই সবসময়
         * একসাথে, আর টেস্টে দুইজন ডিলার রাখা হয়েছে।
         */
        $scope = fn () => LedgerEntry::query()
            ->withoutGlobalScope('user-branch')
            ->forParty('customer', (int) $dealer->id);

        /*
         * খোলার ব্যালান্স — ছাঁকনির **আগের** সব সারির নিট।
         *
         * ⚠️ এটা না গুনলে ব্যালান্সের কলাম শূন্য থেকে শুরু হত, আর ডিলার
         * পড়তেন "আমার কোনো বকেয়া ছিল না" — যেটা প্রায় সবসময়ই মিথ্যা।
         *
         * ── কেন কাটাকাটিটা তারিখেই, `id` ধরে নয় ──────────────────────
         * ছাঁকনির সীমা একটা **তারিখ**, আর নিচের তালিকা নেয়
         * `trx_date >= $from`. তাই "আগের" মানে হুবহু `trx_date < $from`
         * — একই তারিখের সারিগুলো সব একদিকে যায়, কোনোটা দুইবার বা
         * শূন্যবার গোনা হয় না।
         *
         * ⓘ `id` লাগত যদি সীমাটা একটা **সারি** হত (যেমন কার্সর দিয়ে
         * পাতা ভাগ)। আজ সেটা নয়, আর কেউ ভবিষ্যতে কার্সর বসালে এই
         * কাটাকাটিটাও তখন `id` ধরে করতে হবে — নইলে সীমানার তারিখের
         * সারিগুলো গোনায় গোলমাল করবে।
         */
        $opening = (string) ($scope()
            ->whereDate('trx_date', '<', $from)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net') ?? '0');

        /*
         * ⚠️ ক্রম দুইটা কলাম ধরে, আর দ্বিতীয়টা বাদ দেওয়া যাবে না।
         *
         * একই তারিখে তিনটা সারি থাকলে ডাটাবেস যেকোনো ক্রমে দিতে পারে।
         * তখন **প্রতিবার পাতা খুললে চলমান ব্যালান্স আলাদা দেখাত**,
         * অথচ একটা সংখ্যাও বদলায়নি — আর ডিলার ধরে নিতেন খাতা ভুল।
         */
        $rows = $scope()
            ->whereDate('trx_date', '>=', $from)
            ->whereDate('trx_date', '<=', $to)
            ->orderBy('trx_date')->orderBy('id')
            ->get();

        return view('sales::portal.ledger', [
            'dealer' => $dealer,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $this->runningBalance($rows, $opening),

            /*
             * ⚠️ সীমাটা কেবল তখনই, যখন কোম্পানি সুইচটা চালু রেখেছে।
             *
             * বন্ধ থাকলে "০" দেখানো যাবে না: ডিলার পড়তেন **তাঁর সীমা
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
     * ⓘ চেকলিস্ট বলে "Lifetime": ডিলার খতিয়ান খোলেন হিসাব মেলাতে, আর
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
