<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Reports\DepositReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * তারিখ পেরোনোর আগে খবরটা পৌঁছায় — অর্থের মানচিত্র §১৪ক ও §১৪খ।
 *
 * ── ⛔ কী ভাঙা ছিল, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * দুইটা পর্দা তারিখগুলো **গুনত**: জমার "মেয়াদ আসছে" ট্যাব আর হাতধারের
 * "মনে করিয়ে দেওয়া" ট্যাব। ⚠️ কিন্তু গোনাটা দেখা যেত কেবল পাতাটা
 * কেউ খুললে — আর যে তারিখটা ফসকে যায়, সেটা ঠিক ওই দিনগুলোতেই ফসকায়
 * যেদিন কেউ পাতাটা খোলেননি।
 *
 * ⭐ FDR-এর মেয়াদ ফসকানো মানে ব্যাংক আপনা থেকে নতুন মেয়াদে টাকাটা
 * আটকে দেয়, প্রায়ই কম হারে — অর্থাৎ ভুলটার দাম আছে, আর সেটা নীরব।
 *
 * ── ⓘ কেন সপ্তাহে একবার, রোজ নয় ─────────────────────────────────────
 * একই FDR-এর জন্য ত্রিশ দিন ধরে রোজ একটা করে খবর মানে ত্রিশটা খবর, আর
 * তারপর মানুষ ঘণ্টাটা দেখা বন্ধ করে দেন — [[NotificationService]]-এর
 * মাথায় লেখা ঠিক সেই রোগটা। ⛔ তাই একই কাগজের খবর সাত দিনে একবার;
 * পাঠানো খবরগুলোই মনে রাখে কবে শেষ গিয়েছিল, নতুন কোনো টেবিল নেই।
 */
final class DueNotices
{
    /** ⓘ একই কাগজের খবর এতদিনে একবারের বেশি নয় */
    public const QUIET_DAYS = 7;

    /** কত দিন আগে থেকে খবর যায় — পর্দার ডিফল্ট জানালার সমান */
    public const WINDOW_DAYS = 30;

    /** ⭐ মেয়াদপূর্তির শেষ ধাপ — এত দিনের মধ্যে (বা পেরোলে) সপ্তাহে সপ্তাহে ([[depositsAboutToMature()]]) */
    public const SOON_DAYS = 7;

    public const MATURING_SOON = 'finance.deposit_maturing_soon';

    public const DPS_DUE = 'finance.dps_instalment_due';

    public const MATURING = 'finance.deposit_maturing';

    public const HAND_LOAN_DUE = 'finance.hand_loan_due';

    // ⭐ ব্যাংক ঋণের কিস্তি আর নবায়ন — অর্থ-মডিউলের পরিকল্পনা ৩.৫, ৬ অক্টোবর ২০২৬
    public const BANK_INSTALMENT_DUE = 'finance.bank_instalment_due';

    public const BANK_RENEWAL_DUE = 'finance.bank_renewal_due';

    // ⭐ বীমা — পরিকল্পনা ৬.৫, ৬ অক্টোবর ২০২৬
    public const INSURANCE_RENEWAL_DUE = 'finance.insurance_renewal_due';

    public const INSURANCE_PREMIUM_DUE = 'finance.insurance_premium_due';

    /** কিস্তির তাগাদা কত দিন আগে থেকে — এক সপ্তাহ, দিন পার হলে প্রতি সপ্তাহে */
    public const INSTALMENT_DAYS = 7;

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * চলতি কোম্পানির দুইটা খবরই পাঠায়।
     *
     * @return array{maturing: int, hand_loans: int} কয়টা খবর গেল
     */
    public function sendAll(): array
    {
        return [
            // ⭐ মেয়াদপূর্তি দুই ধাপে — ৩০ দিনে একবার, ৭ দিনে (বা পেরোলে) সপ্তাহে সপ্তাহে (পরিকল্পনা ৪.৪, ৬ অক্টোবর ২০২৬)
            'maturing' => $this->maturingDeposits() + $this->depositsAboutToMature(),
            // ⭐ DPS-এর বকেয়া কিস্তি — কিস্তির দিন পেরিয়েছে, টাকা খাতায় বসেনি (পরিকল্পনা ৪.৩)
            'dps' => $this->dpsInstalmentsDue(),
            'hand_loans' => $this->handLoansDue(),
            // ⭐ ভাড়া — চুক্তি শেষের ৬০/৩০ দিন আগে আর বকেয়া ([[RentalNotices]], পরিকল্পনা ৫ঘ, ৬ অক্টোবর ২০২৬)
            'rentals' => array_sum(app(RentalNotices::class)->sendAll()),
            'bank_loans' => $this->bankInstalmentsDue() + $this->bankRenewalsDue(),
            // ⭐ বীমা — নবায়নের ৩০ দিন আগে, আর বাকি প্রিমিয়াম ([[InsuranceDues]], পরিকল্পনা ৬.৫, ৬ অক্টোবর ২০২৬)
            'insurance' => $this->insuranceRenewalsDue() + $this->insurancePremiumsDue(),
        ];
    }

    /**
     * §১৪ক — যে জমার মেয়াদ সামনের ত্রিশ দিনে শেষ।
     */
    public function maturingDeposits(): int
    {
        $rows = Deposit::query()
            // ⓘ ঠিকানাটা ধরনের ইস্যুকারী ধরে বসে — নাহলে প্রতি সারিতে একটা কোয়েরি
            ->with('kind')
            ->open()
            ->whereNotNull('matures_on')
            ->where('matures_on', '<=', now()->addDays(self::WINDOW_DAYS)->toDateString())
            // ⓘ শেষ সাত দিন পরের ধাপের ([[depositsAboutToMature()]]) — এই ধাপ কেবল ৩০ থেকে ৮ দিন
            ->where('matures_on', '>', now()->addDays(self::SOON_DAYS)->toDateString())
            ->orderBy('matures_on')
            ->get();

        $sent = 0;

        foreach ($rows as $deposit) {
            $url = route('finance.deposit.show', [
                'issuer' => $deposit->kind->issuer,
                'deposit' => $deposit->id,
            ]);

            // ⓘ এই ধাপে একবারই — শেষ সাত দিনের ধাপ আবার মনে করায় (পরিকল্পনা: "৩০ আর ৭ দিন আগে")
            $sent += $this->tell(
                self::MATURING,
                'finance.deposit.view',
                $url,
                __('finance::message.notice_maturing', [
                    'document' => $deposit->document_no,
                    'institution' => $deposit->institution,
                ]),
                __('finance::message.notice_maturing_body', [
                    'date' => $deposit->matures_on?->translatedFormat('j M Y') ?? '—',
                    'days' => $this->daysLeft($deposit->matures_on),
                ]),
                quietDays: null,
            );
        }

        return $sent;
    }

    /**
     * ⭐ মেয়াদপূর্তির শেষ ধাপ — সাত দিনের মধ্যে, বা মেয়াদ পেরিয়েছে অথচ জমা এখনো খোলা (ভাঙানো বা নবায়ন লেখা হয়নি); সপ্তাহে
     * সপ্তাহে, যতদিন না কেউ জমাটা বন্ধ বা নবায়ন করেন (পরিকল্পনা ৪.৪, ৬ অক্টোবর ২০২৬)।
     */
    public function depositsAboutToMature(): int
    {
        $rows = Deposit::query()->with('kind')->open()
            ->whereNotNull('matures_on')
            ->where('matures_on', '<=', now()->addDays(self::SOON_DAYS)->toDateString())
            ->orderBy('matures_on')
            ->get();

        $sent = 0;

        foreach ($rows as $deposit) {
            $days = $this->daysLeft($deposit->matures_on);

            $sent += $this->tell(
                self::MATURING_SOON,
                'finance.deposit.view',
                route('finance.deposit.show', ['issuer' => $deposit->kind->issuer, 'deposit' => $deposit->id]),
                __('finance::deposit_report.notice_soon', ['document' => $deposit->document_no, 'institution' => $deposit->institution]),
                $days < 0
                    ? __('finance::deposit_report.notice_matured_body', ['date' => $deposit->matures_on->translatedFormat('j M Y'), 'days' => -$days])
                    : __('finance::message.notice_maturing_body', ['date' => $deposit->matures_on->translatedFormat('j M Y'), 'days' => $days]),
            );
        }

        return $sent;
    }

    /**
     * ⭐ DPS-এর বকেয়া কিস্তি — কিস্তির সময়সূচির "বকেয়া" ঘর ([[DepositReports::INSTALMENTS]]), আজ পর্যন্ত; জমা ধরে একটা
     * খবর, সপ্তাহে একবার। ⓘ নিজের হিসাব নয় — রিপোর্টই বলে কোন মাস বকেয়া, তাই খবর আর পাতা কখনো আলাদা কথা বলে না।
     */
    public function dpsInstalmentsDue(): int
    {
        $rows = app(ReportEngine::class)->run(DepositReports::INSTALMENTS, [
            'from' => now()->subYears(10)->toDateString(), 'to' => now()->toDateString(),
        ], 1, 100000)->rows;

        $owed = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            if (bccomp((string) $row['overdue'], '0', 4) > 0) {
                $id = (int) $row['source_id'];
                $owed[$id] ??= ['months' => 0, 'amount' => '0'];
                $owed[$id]['months']++;
                $owed[$id]['amount'] = bcadd($owed[$id]['amount'], (string) $row['overdue'], 4);
            }
        }

        $sent = 0;

        foreach (Deposit::query()->with('kind')->whereKey(array_keys($owed))->get() as $deposit) {
            $sent += $this->tell(
                self::DPS_DUE,
                'finance.deposit.view',
                route('finance.deposit.show', ['issuer' => $deposit->kind->issuer, 'deposit' => $deposit->id]),
                __('finance::deposit_report.notice_dps', ['document' => $deposit->document_no, 'institution' => $deposit->institution]),
                __('finance::deposit_report.notice_dps_body', [
                    'count' => $owed[$deposit->id]['months'],
                    'amount' => Money::format($owed[$deposit->id]['amount']),
                ]),
            );
        }

        return $sent;
    }

    /**
     * §১৪খ — যে হাতধারের তারিখ পেরিয়েছে বা ত্রিশ দিনের ভিতরে।
     *
     * ⛔ তারিখহীন ধার বাদ — *"যখন পারো দিও"* ধরনের ধারে মনে করিয়ে
     * দেওয়ার কিছু নেই, আর ওগুলো ঢুকলে সত্যিকারের তাগাদাগুলো চোখ
     * এড়াত। ⓘ পর্দাটাও ঠিক এই নিয়মেই গোনে ([[HandLoanController::index()]])।
     */
    public function handLoansDue(): int
    {
        $soon = now()->addDays(self::WINDOW_DAYS)->toDateString();

        $rows = HandLoanAccount::query()
            // ⓘ বার্তায় মানুষটার নাম বসে — নাহলে প্রতি সারিতে একটা কোয়েরি
            ->with('person')
            ->where(fn ($q) => $q->whereNotNull('next_due_on')->orWhereNotNull('due_on'))
            ->get()
            ->filter(function (HandLoanAccount $account) use ($soon) {
                $due = $account->next_due_on ?? $account->due_on;

                return $due !== null && $due->toDateString() <= $soon;
            });

        $sent = 0;

        foreach ($rows as $account) {
            /*
             * ⚠️ চুকে যাওয়া খাতা বাদ — হিসাবটা খতিয়ান থেকে, সারিতে
             * রাখা কোনো সংখ্যা থেকে নয় ([[HandLoanService::standing()]])।
             * ⛔ শোধ হয়ে যাওয়া ধারের তাগাদা যায় একবার, আর তারপর
             * মানুষ বাকিগুলোও বিশ্বাস করেন না।
             */
            if (bccomp($this->balanceOf($account), '0', 4) === 0) {
                continue;
            }

            $due = $account->next_due_on ?? $account->due_on;

            $sent += $this->tell(
                self::HAND_LOAN_DUE,
                'finance.hand_loan.view',
                route('finance.hand_loan.show', $account->id),
                __('finance::message.notice_hand_loan', [
                    // ⓘ পর্দায় যে নামটা লেখা থাকে, হুবহু সেটাই ([[hand-loan/show]])
                    'person' => $account->person?->name() ?? '—',
                ]),
                __('finance::message.notice_hand_loan_body', [
                    'date' => $due->translatedFormat('j M Y'),
                    'days' => $this->daysLeft($due),
                ]),
            );
        }

        return $sent;
    }

    /**
     * ⭐ ব্যাংক ঋণের কিস্তি — দিন পার, বা সামনের সাত দিনে (পরিকল্পনা ৩.৫)। ঋণ ধরে একটা খবর, সবচেয়ে পুরনো বাকি কিস্তির;
     * কিস্তির তালিকা [[BankFacilityService::instalmentsDue()]]-এর, রিপোর্ট আর ড্যাশবোর্ড যেটা পড়ে।
     */
    public function bankInstalmentsDue(): int
    {
        $sent = 0;
        $seen = [];

        foreach (app(BankFacilityService::class)->instalmentsDue(self::INSTALMENT_DAYS) as $due) {
            $facility = $due['facility'];

            if (isset($seen[$facility->id])) {
                continue;
            }

            $seen[$facility->id] = true;

            $sent += $this->tell(
                self::BANK_INSTALMENT_DUE,
                'finance.bank_facility.view',
                route('finance.bank_facility.show', $facility->id),
                __('finance::bank_loan_report.notice_instalment', ['facility' => trim($facility->bank.' · '.$facility->document_no, ' ·')]),
                __('finance::bank_loan_report.notice_instalment_body', [
                    'date' => Carbon::parse($due['due_on'])->translatedFormat('j M Y'),
                    'amount' => Money::format($due['amount']),
                    'no' => $due['month'],
                ]),
            );
        }

        return $sent;
    }

    /**
     * ⭐ ব্যাংক ঋণের নবায়ন বা মেয়াদ — ত্রিশ দিনের ভিতরে, বা পেরিয়ে গেছে ([[BankFacilityService::dueForRenewal()]]; পরিকল্পনা ৩.৫)।
     */
    public function bankRenewalsDue(): int
    {
        $sent = 0;

        foreach (app(BankFacilityService::class)->dueForRenewal() as $facility) {
            $sent += $this->tell(
                self::BANK_RENEWAL_DUE,
                'finance.bank_facility.view',
                route('finance.bank_facility.show', $facility->id),
                __('finance::bank_loan_report.notice_renewal', ['facility' => trim($facility->bank.' · '.$facility->document_no, ' ·')]),
                __('finance::bank_loan_report.notice_renewal_body', ['date' => $facility->renews_on->translatedFormat('j M Y')]),
            );
        }

        return $sent;
    }

    /**
     * ⭐ বীমার নবায়ন — মেয়াদ শেষের ৩০ দিনের ভিতরে, বা পেরিয়ে গেছে ([[InsuranceDues::renewals()]]); পলিসি ধরে সপ্তাহে একবার।
     */
    public function insuranceRenewalsDue(): int
    {
        $sent = 0;

        foreach (app(InsuranceDues::class)->renewals() as $policy) {
            $sent += $this->tell(
                self::INSURANCE_RENEWAL_DUE,
                'finance.insurance.view',
                route('finance.insurance.show', $policy->id),
                __('finance::insurance_alert.notice_renewal', ['policy' => $policy->policy_no, 'insurer' => $policy->institution?->name() ?? '']),
                __('finance::insurance_alert.notice_renewal_body', ['date' => $policy->ends_on->translatedFormat('j M Y'), 'days' => $policy->daysLeft()]),
            );
        }

        return $sent;
    }

    /**
     * ⭐ বাকি বীমা প্রিমিয়াম — দিন পার, বা সামনের সাত দিনে ([[InsuranceDues::premiumsDue()]]); প্রিমিয়াম ধরে সপ্তাহে একবার।
     */
    public function insurancePremiumsDue(): int
    {
        $sent = 0;

        foreach (app(InsuranceDues::class)->premiumsDue() as $premium) {
            $sent += $this->tell(
                self::INSURANCE_PREMIUM_DUE,
                'finance.insurance.view',
                route('finance.insurance.show', $premium->policy_id),
                __('finance::insurance_alert.notice_premium', ['policy' => $premium->policy?->policy_no ?? '']),
                __('finance::insurance_alert.notice_premium_body', [
                    'amount' => Money::format($premium->amount),
                    'date' => $premium->period_from->translatedFormat('j M Y'),
                ]),
            );
        }

        return $sent;
    }

    /**
     * যাঁদের পর্দাটা দেখার অধিকার আছে, তাঁদের বলা — আর যাঁকে এই সপ্তাহে
     * এই কাগজের খবর দেওয়া হয়েছে তাঁকে আবার নয়।
     *
     * @return int কয়জনের কাছে সত্যিই গেল
     */
    private function tell(string $type, string $permission, string $url, string $title, string $body, ?int $quietDays = self::QUIET_DAYS): int
    {
        $sent = 0;

        foreach ($this->whoCan($permission) as $user) {
            if ($this->toldRecently($user->id, $type, $url, $quietDays)) {
                continue;
            }

            // ⭐ একই কাগজের একই দিনের খবর একবারই — ক্রন দুইবার চললেও (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)
            if ($this->notifications->send($user, $type, $title, $body, $url, key: $type.':'.sha1($url).':'.now()->toDateString()) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * এই কোম্পানির যে ব্যবহারকারীদের এই অনুমতি আছে।
     *
     * ── ⚠️ কেন `can()` ধরে, ভূমিকার তালিকা ধরে নয় ───────────────────
     * অনুমতিটা ভূমিকা থেকেও আসে, আবার ব্যক্তির ব্যতিক্রম থেকেও
     * ([[PermissionOverrides]]) — শেষেরটা কেবল `can()` জানে। ⛔ ভূমিকা
     * ধরে খুঁজলে যাঁর ব্যক্তিগত ব্যতিক্রমে অধিকারটা **কেড়ে নেওয়া**
     * হয়েছে, তিনিও খবর পেতেন।
     *
     * @return Collection<int, User>
     */
    private function whoCan(string $permission): Collection
    {
        $companyId = CompanyContext::id();

        return User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->get()
            ->filter(fn (User $user) => $user->can($permission))
            ->values();
    }

    /**
     * ⓘ "সম্প্রতি বলা হয়েছে" — পাঠানো খবরগুলোই মনে রাখে।
     */
    /** ⓘ `$quietDays` `null` — কখনো আবার নয় (একবারের ধাপ) */
    private function toldRecently(int $userId, string $type, string $url, ?int $quietDays = self::QUIET_DAYS): bool
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('url', $url)
            ->when($quietDays !== null, fn ($q) => $q->where('created_at', '>=', now()->subDays($quietDays)))
            ->exists();
    }

    /** পেরিয়ে গেলে ঋণাত্মক — বার্তাটাই ঠিক করে কোন কথাটা লেখা হবে */
    private function daysLeft(?Carbon $date): int
    {
        return $date === null ? 0 : (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
    }

    private function balanceOf(HandLoanAccount $account): string
    {
        return (string) app(HandLoanService::class)->balanceOf($account);
    }
}
