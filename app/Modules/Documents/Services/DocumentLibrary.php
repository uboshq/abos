<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Engines\Attachment\NotASlip;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ডকুমেন্টের সব লেখার কাজ — তোলা, নতুন ভার্সন, পুরনো ভার্সন ফেরানো, বিবরণ বদল,
 * আর্কাইভ, ফেরানো, মোছা, আর ফাইল খোলা (§৬, §৯; ৮ অক্টোবর ২০২৬)।
 *
 * ⓘ কন্ট্রোলার পাতলা: অনুমতি দেখে আর এখানে পাঠায়। ⭐ প্রতিটা কাজ অডিটে একটা নামসহ
 * সারি লেখে ([[IsAudited::auditAction()]]) — দেখা, প্রিভিউ, নামানো, ছাপা, নতুন
 * ভার্সন, আর্কাইভ, ফেরানো। ⛔ আলাদা কোনো অডিট টেবিল নেই; ABOS-এর একটাই খাতা।
 */
final class DocumentLibrary
{
    /** নম্বর সিরিজের ধরন — module.php-র `doc_types`-এ ঘোষিত */
    public const SERIES = 'DOC';

    /** সংযুক্তির খাতায় এই মডিউলের নাম */
    public const MODULE = 'documents';

    /**
     * সংযুক্তির খাতায় উৎসের ধরন।
     *
     * ⛔ ইচ্ছে করেই ড্রিলের কোনো নাম নয়: সাধারণ সংযুক্তির দরজা
     * (`/attachments/{id}`, [[AttachmentController::download()]]) উৎস চিনতে না পারলে ৪০৪
     * দেয়। ⚠️ চিনলে ঐ দরজা দিয়ে গোপনীয়তা আর অডিট দুইটাই পাশ কাটিয়ে ফাইল নামানো যেত।
     */
    public const ENTITY = 'dms_document';

    public function __construct(
        private readonly AttachmentEngine $attachments,
        private readonly NumberSeriesEngine $numbers,
    ) {}

    /**
     * ফাইলগুলো তোলা — প্রতিটা ফাইল নিজের ডকুমেন্ট, প্রতিটার প্রথম ভার্সন v1.0।
     *
     * ⓘ একটা ফাইল: নাম ফাঁকা থাকলে ফাইলের নাম। ⓘ অনেক ফাইল: নাম দিলে "নাম — ফাইলের নাম",
     * না দিলে কেবল ফাইলের নাম — যাতে দশটা কাগজ একই নামে না বসে।
     *
     * ⚠️ সব এক লেনদেনে: একটা ফিরলে কোনোটাই বসে না।
     *
     * @param  list<UploadedFile>  $files
     * @param  array<string, mixed>  $details
     * @return list<Document>
     */
    public function upload(array $files, array $details): array
    {
        $many = count($files) > 1;

        return DB::transaction(function () use ($files, $details, $many) {
            $made = [];

            foreach ($files as $file) {
                $stem = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: $file->getClientOriginalName();
                $given = trim((string) ($details['name'] ?? ''));

                $name = match (true) {
                    $given === '' => $stem,
                    $many => $given.' — '.$stem,
                    default => $given,
                };

                $document = new Document([
                    ...$this->detailFields($details),
                    'name' => mb_substr($name, 0, 191),
                    'status' => DocumentCatalog::DRAFT,
                    'created_by' => Actor::userId(),
                    'updated_by' => Actor::userId(),
                ]);

                $document->document_no = $this->numbers->next(
                    self::SERIES,
                    $document->branch_id,
                    sourceType: Document::class,
                );

                $document->save();

                $this->addVersionRow($document, $this->store($file, $document), 1, 0, $details['comment'] ?? null);

                $made[] = $document;
            }

            return $made;
        });
    }

    /**
     * নতুন ভার্সন — ছোট বদলে v1.0 → v1.1, বড় বদলে v1.1 → v2.0 (§৯)।
     *
     * ⛔ আগের ফাইল ছোঁয়া হয় না; ⓘ অনুমোদিত কাগজেও চলে — বদলের পথ এটাই।
     */
    public function addVersion(Document $document, UploadedFile $file, bool $major, ?string $comment): DocumentVersion
    {
        return DB::transaction(function () use ($document, $file, $major, $comment) {
            $locked = $this->lock($document);
            [$nextMajor, $nextMinor] = $this->nextNumber($locked, $major);

            $version = $this->addVersionRow($locked, $this->store($file, $locked), $nextMajor, $nextMinor, $comment);

            $locked->auditAction('document_version_added', 'v'.$version->label());

            return $version;
        });
    }

    /**
     * পুরনো ভার্সন ফেরানো — নতুন ভার্সন হিসেবে, পুরনো ফাইলটাই (§৯ "Restore")।
     *
     * ⓘ ইতিহাস কখনো পেছনে হাঁটে না: v1.0 ফেরালে চলতি হয় v1.2, যার ফাইল v1.0-র।
     * ⚠️ তাই v1.1-ও থেকে যায় — কেউ জানতে চাইলে দেখা যায় মাঝে কী ছিল।
     */
    public function restoreVersion(Document $document, DocumentVersion $from, ?string $comment): DocumentVersion
    {
        return DB::transaction(function () use ($document, $from, $comment) {
            $locked = $this->lock($document);
            [$nextMajor, $nextMinor] = $this->nextNumber($locked, false);

            $version = $this->addVersionRow(
                $locked,
                (int) $from->attachment_id,
                $nextMajor,
                $nextMinor,
                $comment,
                restoredFrom: (int) $from->id,
            );

            $locked->auditAction('doc_version_restored', 'v'.$from->label().' → v'.$version->label());

            return $version;
        });
    }

    /**
     * বিবরণ বদল — নাম, ধরন, ফোল্ডার, তারিখ, মেয়াদ, গোপনীয়তা, ট্যাগ।
     *
     * ⛔ ফাইল এখানে বদলায় না — সেটা নতুন ভার্সন। ⓘ অডিট নিজেই আগের ও পরের মান লেখে
     * ([[IsAudited]])। অনুমোদিত কাগজ এখানে আসেই না ([[DocumentPolicy::update()]])।
     *
     * @param  array<string, mixed>  $details
     */
    public function update(Document $document, array $details): Document
    {
        $document->fill([
            ...$this->detailFields($details),
            'name' => mb_substr(trim((string) $details['name']), 0, 191),
            'updated_by' => Actor::userId(),
        ])->save();

        return $document;
    }

    /**
     * আর্কাইভ — সেন্টার থেকে সরে, মোছে না; ফাইল আর ভার্সন যেমন ছিল।
     *
     * ⓘ সারি নিঃশব্দে বাঁচে (`saveQuietly`), আর অডিটে একটাই নামসহ সারি — নাহলে একই
     * ঘটনা "বদল" আর "আর্কাইভ" দুই নামে দুইবার লেখা হত।
     */
    public function archive(Document $document, ?string $reason): void
    {
        DB::transaction(function () use ($document, $reason) {
            $document->forceFill([
                'archived_from_status' => $document->status,
                'status' => DocumentCatalog::ARCHIVED,
                'archived_at' => now(),
                'archived_by' => Actor::userId(),
                'updated_by' => Actor::userId(),
            ])->saveQuietly();

            $document->auditAction('document_archived', $reason);
        });
    }

    /** আর্কাইভ থেকে ফেরানো — আগের অবস্থায়, সেন্টারে আবার দেখা যায় */
    public function unarchive(Document $document): void
    {
        DB::transaction(function () use ($document) {
            $document->forceFill([
                'status' => $document->archived_from_status ?: DocumentCatalog::DRAFT,
                'archived_from_status' => null,
                'archived_at' => null,
                'archived_by' => null,
                'updated_by' => Actor::userId(),
            ])->saveQuietly();

            $document->auditAction('document_unarchived');
        });
    }

    /**
     * মোছা — নরম: সারি আর ফাইল থাকে, কেবল কোথাও দেখা যায় না।
     *
     * ⓘ কে মুছলেন সারিতে লেখা (`deleted_by`), আর অডিট নিজেই "মোছা" লেখে। ফেরানোর পর্দা
     * রিসাইকেল বিনের (§১৯) — পরের ধাপে।
     */
    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document) {
            $document->forceFill(['deleted_by' => Actor::userId()])->saveQuietly();
            $document->delete();
        });
    }

    /**
     * একটা ভার্সনের ফাইল খোলা — পাতার ভিতরে (প্রিভিউ, ছাপা) বা নামানো হিসেবে।
     *
     * ⛔ পাতার ভিতরে কেবল ছবি আর PDF ([[AttachmentEngine::inlineType()]]); বাকি সব
     * নামানো হিসেবে যায়, যা-ই চাওয়া হোক — একটা পুরনো সারির ভুল ধরনও এখানে আটকায়।
     * ⓘ কোন কাজে খোলা হলো, সেটা অডিটে আলাদা নামে।
     */
    public function stream(Document $document, DocumentVersion $version, string $how): StreamedResponse
    {
        $file = Attachment::query()->find($version->attachment_id);

        abort_if($file === null || ! $this->attachments->exists($file), 404);

        $inline = $how === 'download' ? null : AttachmentEngine::inlineType($file->mime_type);

        $document->auditAction(match ($how) {
            'preview' => 'document_previewed',
            'print' => 'document_printed',
            default => 'document_downloaded',
        }, 'v'.$version->label());

        return response()->streamDownload(
            fn () => print ($this->attachments->contents($file)),
            $file->original_name,
            [
                'Content-Type' => $inline ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            $inline === null ? 'attachment' : 'inline',
        );
    }

    /** বিস্তারিত পাতা খোলা — অডিটে "দেখা" */
    public function viewed(Document $document): void
    {
        $document->auditAction('document_viewed');
    }

    /** পাতার ভিতরে খোলা যায় কি না — প্রিভিউ আর ছাপার বোতামের জন্য */
    public function opensInline(?DocumentVersion $version): bool
    {
        if ($version === null) {
            return false;
        }

        $file = Attachment::query()->find($version->attachment_id);

        return $file !== null && AttachmentEngine::inlineType($file->mime_type) !== null;
    }

    /**
     * ফর্ম থেকে আসা বিবরণের ঘরগুলো — কেবল যেগুলো মানুষ বদলাতে পারেন।
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function detailFields(array $details): array
    {
        $tags = array_values(array_unique(array_filter(array_map(
            fn ($t) => mb_substr(trim((string) $t), 0, 40),
            explode(',', (string) ($details['tags'] ?? '')),
        ))));

        return [
            'doc_type' => $details['doc_type'],
            'folder' => $details['folder'],
            'branch_id' => $details['branch_id'] ?? null,
            'department_id' => $details['department_id'] ?? null,
            'owner_id' => $details['owner_id'] ?? Actor::userId(),
            'document_date' => filled($details['document_date'] ?? null) ? Carbon::parse($details['document_date']) : null,
            'expiry_date' => filled($details['expiry_date'] ?? null) ? Carbon::parse($details['expiry_date']) : null,
            'confidentiality' => $details['confidentiality'],
            'tags' => $tags === [] ? null : mb_substr(implode(', ', $tags), 0, 500),
            'description' => filled($details['description'] ?? null) ? trim((string) $details['description']) : null,
        ];
    }

    /** ফাইলটা সংযুক্তির খাতায় — ফিরলে ফর্মের ভুল, ৫০০ নয় */
    private function store(UploadedFile $file, Document $document): int
    {
        try {
            return (int) $this->attachments->store(
                file: $file,
                module: self::MODULE,
                entity: self::ENTITY,
                entityId: (int) $document->getKey(),
                maxBytes: DocumentFiles::MAX_BYTES,
                only: DocumentFiles::ALLOWED,
            )->getKey();
        } catch (NotASlip $refused) {
            throw ValidationException::withMessages([
                'file' => __('documents::message.file_'.$refused->reason, ['max' => '10 MB']),
            ]);
        } catch (AttachmentException) {
            throw ValidationException::withMessages([
                'file' => __('documents::message.file_wrong_kind', ['max' => '10 MB']),
            ]);
        }
    }

    private function addVersionRow(
        Document $document,
        int $attachmentId,
        int $major,
        int $minor,
        ?string $comment,
        ?int $restoredFrom = null,
    ): DocumentVersion {
        $version = DocumentVersion::query()->create([
            'company_id' => $document->company_id ?? CompanyContext::id(),
            'branch_id' => $document->branch_id,
            'document_id' => $document->getKey(),
            'major' => $major,
            'minor' => $minor,
            'attachment_id' => $attachmentId,
            // ⭐ SHA-256 — ভার্সনের নিজের সারিতে, সংযুক্তির খাতা থেকে একবার পড়া (§২১ file_hash)
            'file_hash' => Attachment::query()->whereKey($attachmentId)->value('checksum'),
            'restored_from_id' => $restoredFrom,
            'comment' => filled($comment) ? mb_substr(trim((string) $comment), 0, 500) : null,
            'created_by' => Actor::userId(),
        ]);

        $document->forceFill([
            'current_version_id' => $version->getKey(),
            'updated_by' => Actor::userId(),
        ])->saveQuietly();

        return $version;
    }

    /**
     * পরের ভার্সনের নম্বর — সবচেয়ে বড়টার পরে (চলতিটার নয়: চলতি সবসময় সবচেয়ে বড়ই,
     * কিন্তু নম্বর বানানো সারিগুলো থেকে পড়লে নিয়মটা কারও মনে রাখার উপর দাঁড়ায় না)।
     *
     * @return array{0: int, 1: int}
     */
    private function nextNumber(Document $document, bool $major): array
    {
        $last = DocumentVersion::query()
            ->where('document_id', $document->getKey())
            ->orderByDesc('major')
            ->orderByDesc('minor')
            ->first();

        if ($last === null) {
            return [1, 0];
        }

        return $major ? [$last->major + 1, 0] : [$last->major, $last->minor + 1];
    }

    /**
     * ⛔ দুইজন একসাথে নতুন ভার্সন তুললে দুইজনই v1.1 পেতেন — কাগজের সারি তালায় পড়ে,
     * দ্বিতীয়জন প্রথমজনের পরে v1.2 পান। ⓘ টেবিলের অনন্য চাবি পেছনের পাহারা।
     */
    private function lock(Document $document): Document
    {
        return Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
    }
}
