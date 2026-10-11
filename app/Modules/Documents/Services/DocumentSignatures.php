<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Attachment;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentSignature;
use App\Modules\Documents\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * সই কেন্দ্র — সই চাওয়া, সই, যাচাই, ইতিহাস (পরিকল্পনা §১১; চতুর্থ ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⭐ নতুন কোনো সই-যন্ত্র নয় ─────────────────────────────────────────
 * সই দেওয়া, "না", "সংশোধনে ফেরত" — ABOS-এর সইয়ের ইনবক্সে ([[ApprovalEngine]])। কে সই দেবেন সেটা
 * অনুমোদনের ধারার পর্দায় (কাজ "ডকুমেন্টে সই"): ⓘ একজন (এক স্তর, একজন), অনেকে (এক স্তরে "সবাই" বা
 * "অন্তত N জন"), পরপর (কয়েক স্তর) — তিনটাই ইঞ্জিনের নিজের ছক।
 *
 * ── ⛔ সই একটা ভার্সনের, একটা হ্যাশের ──────────────────────────────────
 * সই চাওয়ার মুহূর্তে চলতি ভার্সন আর তার SHA-256 অনুরোধে বাঁধা (`payload`)। শেষ সই পড়লে প্রতিটা
 * সই সেই ভার্সন আর হ্যাশে বসে ([[DocumentSignature]])। ⚠️ পরে নতুন ভার্সন উঠলে সইগুলো পুরনো ভার্সনেরই
 * থাকে — কাগজ "আবার সই লাগবে" দেখায় ([[state()]])। যাচাই ([[verify()]]) ডিস্কের ফাইল আবার হ্যাশ করে
 * মেলায় — ফাইল কেউ বদলালে ধরা পড়ে।
 */
final class DocumentSignatures
{
    public const ACTION = 'signature';

    /** কাগজের সইয়ের অবস্থা */
    public const NONE = 'none';

    public const PENDING = 'pending';

    public const SIGNED = 'signed';

    public const STALE = 'stale';

    public function __construct(
        private readonly DocumentApproval $approvals,
        private readonly ApprovalEngine $engine,
        private readonly AttachmentEngine $attachments,
        private readonly DocumentNotices $notices,
    ) {}

    public function request(Document $document, ?string $note): Approval
    {
        $approval = DB::transaction(function () use ($document, $note) {
            $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $version = $locked->currentVersion;

            if ($version === null || $locked->isArchived()) {
                throw ValidationException::withMessages(['signature' => __('documents::message.cannot_ask_signature')]);
            }

            if ($this->state($locked) === self::SIGNED) {
                throw ValidationException::withMessages(['signature' => __('documents::message.already_signed')]);
            }

            $approval = $this->approvals->stopping(
                document: $locked,
                module: DocumentWorkflow::MODULE,
                action: self::ACTION,
                amount: null,
                reason: filled($note) ? mb_substr(trim((string) $note), 0, 255) : $locked->name,
                payload: ['version_id' => (int) $version->id, 'file_hash' => (string) $version->file_hash, 'version' => 'v'.$version->label()],
            );

            // ⛔ সই মানে কারও সই — ধারা না থাকলে "সই হয়ে গেছে" বলা মিথ্যা হত
            if ($approval === null) {
                throw ValidationException::withMessages(['signature' => __('documents::message.no_signature_flow')]);
            }

            if ($approval->status === Approval::REJECTED) {
                throw ValidationException::withMessages(['signature' => __('documents::message.change_before_resubmit')]);
            }

            $locked->auditAction('signature_requested', 'v'.$version->label());

            return $approval;
        });

        $this->notices->signatureRequired($document->fresh() ?? $document, $approval);

        return $approval;
    }

    /**
     * শেষ সিদ্ধান্ত — সব সই পড়লে প্রতিটা সই নিজের সারিতে; "না" হলে অডিটে কারণসহ।
     *
     * ⓘ দুইবার খবর এলেও একবার — সারির অনন্য চাবি (অনুরোধ + মানুষ + স্তর)।
     */
    public function decided(Approval $approval): void
    {
        $document = Document::query()->withoutGlobalScopes()
            ->where('company_id', $approval->company_id)
            ->whereKey($approval->approvable_id)
            ->first();

        if ($document === null) {
            return;
        }

        if ($approval->status !== Approval::APPROVED) {
            $last = $approval->decisions()->orderByDesc('id')->first();
            $document->auditAction('signature_refused', $last?->remarks);
            $this->notices->signatureRefused($document, $approval);

            return;
        }

        $versionId = (int) ($approval->payload['version_id'] ?? 0);
        $hash = (string) ($approval->payload['file_hash'] ?? '');
        $made = 0;

        DB::transaction(function () use ($approval, $document, $versionId, $hash, &$made) {
            foreach ($approval->decisions()->where('decision', ApprovalDecision::APPROVED)->orderBy('id')->get() as $decision) {
                $exists = DocumentSignature::query()
                    ->where('approval_id', $approval->id)
                    ->where('user_id', $decision->user_id)
                    ->where('level', $decision->level)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DocumentSignature::query()->create([
                    'company_id' => $approval->company_id,
                    'document_id' => $document->id,
                    'version_id' => $versionId,
                    'file_hash' => $hash,
                    'approval_id' => $approval->id,
                    'user_id' => $decision->user_id,
                    'level' => $decision->level,
                    'signed_at' => $decision->decided_at ?? now(),
                ]);
                $made++;
            }

            if ($made > 0) {
                $document->auditAction('document_signed', (string) ($approval->payload['version'] ?? ''));
            }
        });

        if ($made > 0) {
            $this->notices->signatureCompleted($document, $approval);
        }
    }

    /** কাগজের সইয়ের অবস্থা — চলমান অনুরোধ, চলতি ভার্সনে সই, পুরনো ভার্সনে সই, নয়তো কিছুই না */
    public function state(Document $document): string
    {
        $latest = $this->engine->latestFor($document, self::ACTION);

        if ($latest !== null && $latest->status === Approval::PENDING) {
            return self::PENDING;
        }

        $signed = DocumentSignature::query()->where('document_id', $document->getKey());

        if ((clone $signed)->where('version_id', $document->current_version_id)->exists()) {
            return self::SIGNED;
        }

        return $signed->exists() ? self::STALE : self::NONE;
    }

    /**
     * যাচাই — সইয়ের হ্যাশ কি এখনো ভার্সনের হ্যাশ, আর ডিস্কের ফাইলটা কি এখনো সেই বাইট?
     *
     * @return array{version: bool, file: bool, current: bool}
     */
    public function verify(DocumentSignature $signature): array
    {
        $version = DocumentVersion::query()->find($signature->version_id);
        $file = $version === null ? null : Attachment::query()->find($version->attachment_id);
        $bytes = $file !== null && $this->attachments->exists($file)
            ? hash('sha256', $this->attachments->contents($file))
            : null;

        $result = [
            'version' => $version !== null && hash_equals((string) $version->file_hash, (string) $signature->file_hash),
            'file' => $bytes !== null && hash_equals($bytes, (string) $signature->file_hash),
            'current' => $version !== null && (int) $version->document?->current_version_id === (int) $version->id,
        ];

        $signature->document?->auditAction('signature_verified',
            ($result['version'] && $result['file']) ? 'ok' : 'mismatch');

        return $result;
    }
}
