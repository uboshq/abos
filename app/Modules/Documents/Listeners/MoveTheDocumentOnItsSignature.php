<?php

declare(strict_types=1);

namespace App\Modules\Documents\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Modules\Documents\Services\DocumentWorkflow;

/**
 * ⭐ ডকুমেন্টের শেষ সিদ্ধান্ত এল — কাগজ নিজে "অনুমোদিত", "বাতিল" বা "বদল চাওয়া"-তে যায়
 * (ডকুমেন্ট পরিকল্পনা §১০; তৃতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ ইঞ্জিন খবরটা ছোড়ে কেবল শেষ সই আর "না"-তে ([[ApprovalEngine]]); কাজটা [[DocumentWorkflow::decided()]]-এ,
 * যা দুইবার খবর এলেও একবার নড়ে।
 */
final class MoveTheDocumentOnItsSignature
{
    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['module'] ?? null) !== DocumentWorkflow::MODULE
            || ($event->payload['action'] ?? null) !== DocumentWorkflow::ACTION
            || ! in_array($event->payload['status'] ?? null, [Approval::APPROVED, Approval::REJECTED], true)) {
            return;
        }

        $approval = Approval::query()->withoutGlobalScopes()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null) {
            return;
        }

        CompanyContext::forCompany((int) $approval->company_id, fn () => app(DocumentWorkflow::class)->decided($approval));
    }
}
