<?php

declare(strict_types=1);

namespace App\Modules\Documents\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Modules\Documents\Services\DocumentSignatures;
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
        $action = $event->payload['action'] ?? null;

        if (($event->payload['module'] ?? null) !== DocumentWorkflow::MODULE
            || ! in_array($action, [DocumentWorkflow::ACTION, DocumentSignatures::ACTION], true)
            || ! in_array($event->payload['status'] ?? null, [Approval::APPROVED, Approval::REJECTED], true)) {
            return;
        }

        $approval = Approval::query()->withoutGlobalScopes()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null) {
            return;
        }

        // ⭐ চতুর্থ ধাপ — সইয়ের অনুরোধের শেষ সিদ্ধান্তে প্রতিটা সই নিজের সারিতে ([[DocumentSignatures::decided()]])
        CompanyContext::forCompany((int) $approval->company_id, fn () => $action === DocumentSignatures::ACTION
            ? app(DocumentSignatures::class)->decided($approval)
            : app(DocumentWorkflow::class)->decided($approval));
    }
}
