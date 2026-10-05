<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Models\InterCompanyTransfer;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\TillHandover;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\InterCompanyService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\TillHandoverService;

/**
 * ⭐ শেষ সই পড়লে হিসাবের কাগজটা নিজেই শেষ হয় — গ১, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬ ([[AccountsSignature]])।
 *
 * ⓘ মালিকের সিদ্ধান্ত (২৭ সেপ্টেম্বর): শেষ সইয়ের পর কাউকে আবার "পাকা করুন" চাপতে হবে না। ফেরত দিলে কাগজটা খসড়াই
 * থাকে — যিনি লিখেছেন তিনি শুধরে আবার পাঠান, বা বাতিল করেন।
 */
final class FinishTheAccountsPaperOnTheLastSignature
{
    public function handle(ApprovalDecided $event): void
    {
        $action = (string) ($event->payload['action'] ?? '');

        // ⓘ `transfer` — স্থানান্তরের সই এখন হস্তান্তরের আগে (Accounts-Finance অডিট ম৬); চাবিটা পুরনো, তাই ACTIONS-এর বাইরে
        if (($event->payload['module'] ?? null) !== AccountsSignature::MODULE
            || (! in_array($action, AccountsSignature::ACTIONS, true) && $action !== 'transfer')) {
            return;
        }

        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval?->status !== Approval::APPROVED) {
            return;
        }

        $paper = $approval->approvable;

        // ⓘ খসড়া থাকলেই — একই সই দুইবার ঘটনা পাঠালে বা কেউ হাতে আগেই পাকা করলে দ্বিতীয়বার কিছু হয় না
        if ($paper instanceof Note && $paper->isDraft()) {
            app(NoteService::class)->confirm($paper);

            return;
        }

        // ⓘ খোলা জের একবারই ওঠে — আগে উঠে থাকলে [[OpeningBalanceService::forAccount()]] নিজেই কিছু করে না
        if ($paper instanceof CashTill && $paper->account !== null) {
            app(OpeningBalanceService::class)->forAccount($paper->account);

            return;
        }

        // ⓘ সই চাওয়ার মুহূর্তের তথ্য সইয়ের সারিতেই ([[DocumentApproval::stopping()]]-এর `payload`)
        if ($paper instanceof FixedAsset) {
            $signed = (array) ($approval->payload ?? []);

            match ($approval->action) {
                AccountsSignature::FIXED_ASSET_REGISTER => app(FixedAssetService::class)->finishRegistered($paper, $signed),
                AccountsSignature::FIXED_ASSET_DISPOSE => $paper->isActive()
                    ? app(FixedAssetService::class)->dispose($paper, (string) ($signed['amount'] ?? '0'), (int) ($signed['into_account_id'] ?? 0), $signed['on'] ?? null)
                    : null,
                default => null,
            };

            return;
        }

        // ⓘ দায়িত্ব হস্তান্তর — সইয়ের অপেক্ষায় থাকলেই বাক্স নতুন জনের (অডিট ম৮)
        if ($paper instanceof TillHandover) {
            if ($paper->isAwaiting()) {
                app(TillHandoverService::class)->finish($paper);
            }

            return;
        }

        // ⓘ সইয়ের অপেক্ষায় থাকলেই — পুরনো ধারার (গ্রহণে সই) কাগজ বা আগেই পাঠানো কাগজে কিছু হয় না
        if ($paper instanceof MoneyTransfer) {
            if ($paper->isAwaiting()) {
                app(MoneyTransferService::class)->finishSigned($paper);
            }

            return;
        }

        if ($paper instanceof InterCompanyTransfer) {
            app(InterCompanyService::class)->finishSigned($paper);

            return;
        }

        // ⓘ চেক যে অবস্থায় থামেছিল সেখানেই থাকলে — অন্য পথে আগেই পাশ বা ফেরত হলে কিছু হয় না
        if ($paper instanceof Cheque && in_array($paper->status, [Cheque::PENDING, Cheque::DEPOSITED, Cheque::CLEARED], true)) {
            match ($approval->action) {
                AccountsSignature::CHEQUE_CLEAR => $paper->status !== Cheque::CLEARED ? app(ChequeService::class)->clear($paper) : null,
                AccountsSignature::CHEQUE_BOUNCE => app(ChequeService::class)->bounce($paper, (string) $approval->requested_reason),
                default => null,
            };
        }
    }
}
