<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Support\Actor;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * অনুমোদনের ধারা — খসড়া → জমা → পর্যালোচনায় → (বদল চাওয়া / বাতিল) → অনুমোদিত → প্রকাশিত
 * (পরিকল্পনা §১০, §২২; তৃতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⭐ নতুন কোনো অনুমোদন-যন্ত্র নয় ────────────────────────────────────
 * সই চাওয়া, স্তর, কে সই করতে পারেন, সময়সীমা, ফরওয়ার্ড — সবই ABOS-এর [[ApprovalEngine]]।
 * ধারা ঠিক হয় অনুমোদনের ধারার পর্দায় (মডিউল "ডকুমেন্ট", কাজ "ডকুমেন্ট অনুমোদন")। এই ক্লাস কেবল
 * কাগজের অবস্থা সেই অনুমোদনের সাথে মিলিয়ে রাখে।
 *
 * ── ⓘ অবস্থা কীভাবে মেলে ──────────────────────────────────────────────
 * জমা দিলে [[DocumentApproval::stopping()]] — ধারা থাকলে অনুরোধ বসে আর কাগজ "জমা"; ধারা না থাকলে
 * (কোম্পানি অনুমোদন চায় না) সাথে সাথে "অনুমোদিত"। প্রথম সই পড়লে "পর্যালোচনায়" ([[sync()]])।
 * শেষ সইয়ে "অনুমোদিত", না-তে "বাতিল", আর সইকারী "সংশোধনে ফেরত" (কারণ-কোড `document`) দিলে
 * "বদল চাওয়া" — ⭐ ইঞ্জিনের নিজের দুই রকম "না" ([[ApprovalDecision::REASONS]])।
 *
 * ⛔ ছাপ ([[DocumentFingerprint]]) কাগজের সারিসহ চলতি ভার্সন ধরে — নতুন ভার্সন উঠলে পুরনো সই
 * আর ঢাকে না, আবার জমা দিতে হয়।
 */
final class DocumentWorkflow
{
    /** অনুমোদনের ধারার পর্দায় এই মডিউলের কাজ — module.php-র `approvals`-এ ঘোষিত */
    public const MODULE = 'documents';

    public const ACTION = 'document';

    public function __construct(
        private readonly DocumentApproval $approvals,
        private readonly ApprovalEngine $engine,
        private readonly DocumentNotices $notices,
    ) {}

    /** জমা দেওয়া যায় এমন অবস্থা — খসড়া, বা ফেরত আসা কাগজ */
    public static function canBeSubmitted(Document $document): bool
    {
        return in_array($document->status, [DocumentCatalog::DRAFT, DocumentCatalog::CHANGES_REQUESTED,
            DocumentCatalog::REJECTED], true) && ! $document->isArchived() && $document->current_version_id !== null;
    }

    public function submit(Document $document, ?string $note): Document
    {
        $approval = DB::transaction(function () use ($document, $note) {
            $locked = $this->lock($document);

            if (! self::canBeSubmitted($locked)) {
                throw ValidationException::withMessages(['status' => __('documents::message.cannot_submit')]);
            }

            $approval = $this->approvals->stopping(
                document: $locked,
                module: self::MODULE,
                action: self::ACTION,
                amount: null,
                reason: filled($note) ? mb_substr(trim((string) $note), 0, 255) : $locked->name,
            );

            // ⛔ আগের "না" — কাগজ বদলায়নি, তাই একই কাগজ আবার পাঠানো যায় না
            if ($approval !== null && $approval->status === Approval::REJECTED) {
                throw ValidationException::withMessages(['status' => __('documents::message.change_before_resubmit')]);
            }

            // ⛔ ধারা বন্ধ থাকলে "অনুমোদিত" নয় — "অনুমোদন ছাড়া প্রকাশিত", অডিটেও তাই (fe, ১১ অক্টোবর ২০২৬)
            $this->move($locked, $approval === null ? DocumentCatalog::PUBLISHED_UNAPPROVED : DocumentCatalog::SUBMITTED);
            $locked->auditAction('document_submitted', $note);

            if ($approval === null) {
                $locked->auditAction('doc_published_unapproved', __('documents::message.no_flow_needed'));
            }

            return $approval;
        });

        $fresh = $document->fresh() ?? $document;

        if ($approval !== null) {
            $this->notices->approvalRequired($fresh, $approval);
        }

        return $fresh;
    }

    /** জমা ফেরত নেওয়া — কেবল যিনি জমা দিয়েছেন, আর কেউ সই করার আগে বা মাঝে */
    public function withdraw(Document $document, User $user): void
    {
        DB::transaction(function () use ($document, $user) {
            $locked = $this->lock($document);
            $approval = $this->pending($locked);

            if ($approval === null || (int) $approval->requested_by !== (int) $user->getKey()) {
                throw ValidationException::withMessages(['status' => __('documents::message.cannot_withdraw')]);
            }

            $this->engine->cancel($approval, $user);
            $this->move($locked, DocumentCatalog::DRAFT);
            $locked->auditAction('document_withdrawn');
        });
    }

    /** প্রকাশ — অনুমোদিত কাগজ সবার জন্য চালু (§১০ Approved → Published) */
    public function publish(Document $document): void
    {
        DB::transaction(function () use ($document) {
            $locked = $this->lock($document);

            if ($locked->status !== DocumentCatalog::APPROVED) {
                throw ValidationException::withMessages(['status' => __('documents::message.cannot_publish')]);
            }

            $this->move($locked, DocumentCatalog::PUBLISHED);
            $locked->auditAction('document_published');
        });
    }

    /**
     * শেষ সিদ্ধান্ত এল — অনুমোদিত, বাতিল, বা সংশোধনে ফেরত ([[MoveTheDocumentOnItsSignature]])।
     *
     * ⓘ দুইবার খবর এলেও একবার: কাগজ কেবল "জমা" বা "পর্যালোচনায়" থাকলেই নড়ে।
     */
    public function decided(Approval $approval): void
    {
        $moved = DB::transaction(function () use ($approval) {
            $document = Document::query()->withoutGlobalScopes()
                ->where('company_id', $approval->company_id)
                ->whereKey($approval->approvable_id)
                ->lockForUpdate()
                ->first();

            if ($document === null || ! in_array($document->status, [DocumentCatalog::SUBMITTED, DocumentCatalog::UNDER_REVIEW], true)) {
                return null;
            }

            $last = $approval->decisions()->orderByDesc('id')->first();

            [$status, $action] = match (true) {
                $approval->status === Approval::APPROVED => [DocumentCatalog::APPROVED, 'document_approved'],
                $last?->reason_code === 'document' => [DocumentCatalog::CHANGES_REQUESTED, 'document_returned'],
                default => [DocumentCatalog::REJECTED, 'document_rejected'],
            };

            $this->move($document, $status);
            $document->auditAction($action, $last?->remarks);

            return $document;
        });

        if ($moved !== null) {
            $this->notices->decided($moved, $approval);
        }
    }

    /**
     * প্রথম সই পড়েছে কিন্তু শেষ সই নয় — "জমা" থেকে "পর্যালোচনায়"।
     *
     * ⓘ ইঞ্জিন মাঝের স্তরের সইয়ে কোনো খবর ছোড়ে না (কেবল শেষে), তাই এটা দেখার সময় মেলানো হয় —
     * বিস্তারিত পাতা খুললে আর ঘণ্টায় একবার নির্ধারিত কাজে ([[DocumentExpiry::run()]])।
     */
    public function sync(Document $document): void
    {
        if ($document->status !== DocumentCatalog::SUBMITTED) {
            return;
        }

        $approval = $this->pending($document);

        /*
         * ⛔ কেবল দেখানো, লেখা নয় (১১ অক্টোবর ২০২৬, documents রিভিউ — GET-এ অবস্থা বদল)। ⓘ আগে কাগজের পাতা খুললেই (GET) তালা ছাড়া
         * অবস্থা বদলে খাতায় বসত, আর `updated_by` হয়ে যেতেন যিনি কেবল দেখছিলেন। মাঝের সইয়ে ইঞ্জিন কোনো ঘটনা ছোড়ে না (কেবল শেষ
         * সিদ্ধান্তে, [[ApprovalDecided]]), তাই "পর্যালোচনায়" এখানে গুনে দেখানো হয় — সারিতে লেখা হয় না; শেষ সিদ্ধান্তে
         * [[decided()]] আসল অবস্থা বসায়।
         */
        if ($approval !== null && $approval->decisions()->where('decision', ApprovalDecision::APPROVED)->exists()) {
            $document->setAttribute('status', DocumentCatalog::UNDER_REVIEW);
        }
    }

    /** এই কাগজের চলমান অনুরোধ — অনুমোদনের অংশে দেখানোর জন্যও */
    public function pending(Document $document): ?Approval
    {
        $latest = $this->engine->latestFor($document, self::ACTION);

        return $latest !== null && $latest->status === Approval::PENDING ? $latest : null;
    }

    public function latest(Document $document): ?Approval
    {
        return $this->engine->latestFor($document, self::ACTION);
    }

    /**
     * অবস্থা বদল — নিঃশব্দে, কারণ প্রতিটা বদল অডিটে নিজের নামে আলাদা লেখা হয়।
     */
    private function move(Document $document, string $status): void
    {
        $document->forceFill(['status' => $status, 'updated_by' => Actor::userId() ?? $document->updated_by])->saveQuietly();
    }

    private function lock(Document $document): Document
    {
        return Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
    }
}
