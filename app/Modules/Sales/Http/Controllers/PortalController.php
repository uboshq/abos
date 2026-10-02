<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Security\LoginLock;
use App\Core\Services\LoginJournal;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\LoginAttempt;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\CustomerPapers;
use App\Modules\Sales\Services\DepositClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    /**
     * খাতায় আর তালায় ডিলারের কোডের আগে এই চিহ্ন।
     *
     * ── ⚠️ কেন আলাদা নামের জগৎ ─────────────────────────────────────────
     * কর্মী আর ডিলার একই `login_history`-তে, আর [[LoginLock]] গোনে টাইপ
     * করা নাম ধরে। ⛔ চিহ্ন ছাড়া কর্মীর দরজায় `CUS-0007` নামে আটটা ভুল
     * ডিলার `CUS-0007`-কে তালাবদ্ধ করত — একটা দরজা দিয়ে আরেকটা বন্ধ
     * করা যেত। ⓘ আর খাতার পর্দায় চিহ্নটা দেখেই বোঝা যায় চেষ্টাটা কোন
     * দরজায়।
     */
    public const JOURNAL_PREFIX = 'portal:';

    /**
     * অচেনা কোডে যার বিপরীতে পাসওয়ার্ড মেলানো হয় — কখনো মেলে না।
     *
     * ⓘ [[CredentialCheck::verify()]]-এর হুবহু একই কৌশল: bcrypt-এর আকারে
     * একটা hash, খরচ ১২, যাতে যাচাইটা আসলটার মতোই সময় নেয়।
     */
    private const DUMMY_HASH = '$2y$12$.....................................................';

    public function __construct(
        private readonly DepositClaimService $claims,
        private readonly CustomerPapers $papers,
        private readonly LoginLock $lock,
        private readonly LoginJournal $logins,
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

        $identifier = self::JOURNAL_PREFIX.trim($data['code']);

        /*
         * ⛔ তালাটা আগে — খোঁজার আগে, পাসওয়ার্ড মেলানোর আগে। নিরীক্ষা,
         * ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ── কী ছিল না ─────────────────────────────────────────────────
         * পাহারা ছিল কেবল `throttle:5,1` — **IP ধরে**। ⚠️ বহু IP থেকে
         * চালানো একটা পাসওয়ার্ড-তালিকা প্রতিটা IP-তে মিনিটে পাঁচের নিচে
         * থাকে, আর তখন একটা ডিলারের কোডে দিনে হাজার চেষ্টা চলত।
         *
         * ⭐ তালাটা কর্মীর দরজার **সেই একই** [[LoginLock]] — দ্বিতীয় কোনো
         * তালা নয়। দুইটা তালা মানে একদিন দুইটা আলাদা নিয়ম।
         *
         * ⓘ পাসওয়ার্ড মেলানোর আগে, কারণ তালাবদ্ধ অবস্থায় bcrypt চালালে
         * ঢুকতে না পারলেও সার্ভারের CPU খাওয়ানো যেত।
         */
        if (($minutes = $this->lock->locked($identifier)) !== null) {
            $this->logins->failed($identifier, null, LoginAttempt::LOCKED);

            throw ValidationException::withMessages([
                'code' => __('auth.locked', ['minutes' => $minutes]),
            ]);
        }

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
            $data['password'], (string) ($c->portal_password ?: self::DUMMY_HASH),
        ));

        /*
         * ⛔ অচেনা কোডেও একটা hash যাচাই — নিরীক্ষা, ২৭ সেপ্টেম্বর ২০২৬।
         *
         * আগে কোড না মিললে উপরের `first()` একটাও যাচাই চালাত না, আর
         * উত্তরটা কয়েক মিলিসেকেন্ডে ফিরত; চেনা কোডে একটা bcrypt লাগত।
         * ⚠️ পর্দার বার্তা এক হলেও **সময়টা** আলাদা ছিল — আর সময় মেপেই
         * বলা যেত কোন কোডগুলোর পোর্টাল চালু। ⓘ এই লাইনটা তাই অপচয় নয়।
         */
        if ($candidates->isEmpty()) {
            Hash::check($data['password'], self::DUMMY_HASH);
        }

        if ($customer === null) {
            /*
             * খাতায় আসল কারণটা, পর্দায় নয়। ⓘ "অচেনা কোড" মানে কেউ কোড
             * আন্দাজ করছেন, "ভুল পাসওয়ার্ড" মানে কেউ একটা চেনা কোডের
             * পাসওয়ার্ড — দুইটা আলাদা ঘটনা, আর তালাটা দুইটাই গোনে।
             */
            $this->logins->failed(
                $identifier,
                null,
                $candidates->isEmpty() ? LoginAttempt::UNKNOWN : LoginAttempt::WRONG_PASSWORD,
            );

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

        /*
         * ⭐ সফল ঢোকাটা খাতায় — তালার গোনা এখান থেকেই শূন্যে ফেরে।
         * ⓘ লেখাটা [[CustomerPapers::recordSignIn()]]-এ, কারণ এখন গার্ডে
         * গ্রাহক বসে গেছেন আর "কে" উত্তরটা সেখান থেকেই আসে।
         */
        $this->papers->recordSignIn($identifier);

        /*
         * ⭐ বিলের QR স্ক্যান করে এলে লগইনের পরে সেই QR-এর পাতাতেই ফেরা — ৩০ সেপ্টেম্বর ২০২৬
         * ([[DeliveryScanController::open()]] ঠিকানাটা রেখে যায়)।
         *
         * ⛔ কেবল নিজের সাইটের পোর্টাল বা স্ক্যানের ঠিকানা। সেশনে কর্মীর কোনো পুরনো ঠিকানা
         * থাকলে ডিলারকে কর্মীর লগইনে ছুড়ে দিত; অন্য সাইটের ঠিকানা হলে লগইনের পরে বাইরে পাঠাত।
         */
        $intended = (string) $request->session()->pull('url.intended', '');

        foreach ([url('/scan/'), url('/portal')] as $ours) {
            if ($intended !== '' && str_starts_with($intended, $ours)) {
                return redirect()->to($intended);
            }
        }

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
     * @param  Collection<int, LedgerEntry>  $rows
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
            // ⛔ কেবল ব্যাংক — MFS নয়; `ofMoneyKind()` নিজেই দল ছাঁকে
            'banks' => Account::query()
                ->ofMoneyKind(Account::BANK)->active()->orderBy('code')->get(),
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
            // ⭐ স্লিপের ছবি — ১ অক্টোবর ২০২৬; ধরন আর মাপ দেখে [[DepositSlip]] (কেবল ছবি/PDF, ৫ MB)
            'slip' => ['nullable', 'file', 'max:'.intdiv(\App\Core\Engines\Attachment\AttachmentEngine::SLIP_MAX_BYTES, 1024)],
        ]);

        unset($data['slip']);
        app(\App\Modules\Sales\Services\DepositSlip::class)->raise($customer, $data, $request->file('slip'));

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
            'hasSlip' => app(\App\Modules\Sales\Services\DepositSlip::class)->of($claim) !== null,
        ]);
    }

    /** নিজের দাবির স্লিপ — অন্যের দাবিতে ৪০৩, ঠিক [[showOwnClaim()]]-এর মতো */
    public function ownClaimSlip(DepositClaim $claim): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_if($claim->customer_id !== $this->customer()->id, 403);

        return app(\App\Modules\Sales\Services\DepositSlip::class)->stream($claim);
    }
}
