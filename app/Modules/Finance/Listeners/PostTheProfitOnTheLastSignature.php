<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Services\ProfitDistribution;

/**
 * ⭐ মুনাফা ঘোষণার শেষ সই পড়ল — ঘোষণাটা খাতায়; "না" হলে বাতিল।
 *
 * ⓘ যুক্তি সবটা [[ProfitDistribution::finishSigned()]] আর [[ProfitDistribution::dropRefused()]]-এ;
 * এখানে কেবল কোন অনুরোধ, কী সিদ্ধান্ত ([[FinishTheHeldSaleOnTheLastSignature]]-এর ছাঁচ)।
 * মালিকের নিয়ম: *যেকোনো টাকা, যেকোনো অঙ্ক — সই লাগে* (২৭ সেপ্টেম্বর ২০২৬)।
 */
final class PostTheProfitOnTheLastSignature
{
    public function __construct(private readonly ProfitDistribution $profit) {}

    public function handle(ApprovalDecided $event): void
    {
        // ⓘ নাম লেখা, ধ্রুবক নয় — `ProfitDistribution::MODULE`/`ACTION`-এর একই বানান; প্রতিটা সই-এ এটা চলে, তাই
        // আগে থেকে চলা প্রক্রিয়ায় (পুরনো ক্লাস লোড করা) ধ্রুবক না পেয়ে অন্য সব সই ভাঙত না
        if (($event->payload['module'] ?? null) !== 'finance'
            || ($event->payload['action'] ?? null) !== 'profit') {
            return;
        }

        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));
        $voucher = $approval?->approvable;

        if (! $voucher instanceof Voucher) {
            return;
        }

        match ($approval->status) {
            Approval::APPROVED => $this->profit->finishSigned($voucher),
            Approval::REJECTED => $this->profit->dropRefused($voucher, __('finance::validation.profit_signature_refused')),
            default => null,
        };
    }
}
