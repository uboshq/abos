<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\DepositAccrualService;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\FinanceSignature;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Finance\Services\InterestAccrualService;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\TenancyService;
use App\Modules\Finance\Services\WithdrawalService;

/**
 * ⭐ অর্থের টাকার কাগজে শেষ সই পড়ল — খাতায়; "না" হলে বাতিল (অডিট গ১, ম২৮, ৪ অক্টোবর ২০২৬)।
 *
 * ⓘ যুক্তি সবটা যার কাগজ তার সেবায় (`finishSigned()` / `dropRefused()`); এখানে কেবল কোন কাজ, কোন কাগজ, কী
 * সিদ্ধান্ত — [[PostTheProfitOnTheLastSignature]]-এর ছাঁচ। মালিকের নিয়ম: *যেকোনো টাকা, যেকোনো অঙ্ক — সই লাগে*।
 *
 * ⚠️ উত্তোলনে কেবল "না" — "হ্যাঁ"-র পরে টাকা কোন খাত থেকে যাবে সেটা মানুষ বলেন ([[WithdrawalService::post()]])।
 */
final class FinishTheFinancePaperOnTheLastSignature
{
    public function handle(ApprovalDecided $event): void
    {
        // ⓘ নাম লেখা, ধ্রুবক নয় — প্রতিটা সই-এ চলে, আগে থেকে চলা প্রক্রিয়ায় নতুন ক্লাস না পেলেও অন্য সই ভাঙবে না
        if (($event->payload['module'] ?? null) !== 'finance') {
            return;
        }

        $action = (string) ($event->payload['action'] ?? '');

        if ($action !== 'withdrawal' && ! in_array($action, FinanceSignature::ACTIONS, true)) {
            return;
        }

        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null || ! in_array($approval->status, [Approval::APPROVED, Approval::REJECTED], true)) {
            return;
        }

        $yes = $approval->status === Approval::APPROVED;
        $paper = $approval->approvable;
        $why = __('finance::validation.signature_refused');

        match (true) {
            $paper instanceof Withdrawal => $yes ? null : app(WithdrawalService::class)->dropRefused($paper),
            $paper instanceof BankFacility => $yes ? app(BankFacilityService::class)->finishSigned($paper) : null,
            $paper instanceof Voucher => $this->voucher($action, $paper, $yes, $why),
            default => null,
        };
    }

    private function voucher(string $action, Voucher $voucher, bool $yes, string $why): void
    {
        match ($action) {
            FinanceSignature::HAND_LOAN => $yes
                ? app(HandLoanService::class)->finishSigned($voucher)
                : app(HandLoanService::class)->dropRefused($voucher, $why),
            // ⭐ মাসিক অর্জিত মুনাফা জমার নিজের ছকে, আলাদা করে চেনা (পরিকল্পনা ৪.২, ৬ অক্টোবর ২০২৬); বাকি জমার কাগজ আগের মতো
            FinanceSignature::DEPOSIT => DepositAccrualService::isAccrual($voucher)
                ? ($yes ? app(DepositAccrualService::class)->finishSigned($voucher) : app(DepositAccrualService::class)->dropRefused($voucher, $why))
                : ($yes ? app(DepositService::class)->finishSigned($voucher) : app(DepositService::class)->dropRefused($voucher, $why)),
            // ⭐ মাসের প্রদেয় ভাড়া ভাড়ার নিজের ছকে, আলাদা করে চেনা (মালিকের সিদ্ধান্ত প্র২, ৬ অক্টোবর ২০২৬); ⛔ চুক্তির পথে গেলে
            // খোলার-জামানত-নয় এমন জার্নালকে "চুক্তি শেষ" ধরে চুক্তি বন্ধ করে দিত
            // ⭐ ভাড়াটের কাগজও ভাড়ার ছকে (প্র৩) — ভাউচারের বিপরীত থেকে চেনা; ⛔ চুক্তির পথে গেলে ওটা ভাড়ার চুক্তি খুঁজে পেত না
            FinanceSignature::RENTAL => match (true) {
                TenancyService::isTenancy($voucher) => $yes
                    ? app(TenancyService::class)->finishSigned($voucher) : app(TenancyService::class)->dropRefused($voucher, $why),
                RentalAccrualService::isAccrual($voucher) => $yes
                    ? app(RentalAccrualService::class)->finishSigned($voucher) : app(RentalAccrualService::class)->dropRefused($voucher, $why),
                default => $yes
                    ? app(RentalContractService::class)->finishSigned($voucher) : app(RentalContractService::class)->dropRefused($voucher, $why),
            },
            FinanceSignature::CAPITALISE => $yes
                ? app(ProfitDistribution::class)->finishCapitalise($voucher)
                : app(ProfitDistribution::class)->dropCapitalise($voucher, $why),
            // ⭐ ব্যাংক ঋণের মাসিক সুদ জমা — ঋণের নিজের সইয়ের ছকে (পরিকল্পনা ৩.৩, ৬ অক্টোবর ২০২৬)
            FinanceSignature::BANK_FACILITY => ! InterestAccrualService::isAccrual($voucher) ? null : ($yes
                ? app(InterestAccrualService::class)->finishSigned($voucher)
                : app(InterestAccrualService::class)->dropRefused($voucher, $why)),
            default => null,
        };
    }
}
