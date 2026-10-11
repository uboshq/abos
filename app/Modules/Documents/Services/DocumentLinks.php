<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Contracts\Drillable;
use App\Core\Contracts\LinkedDocuments;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Search\SearchEngine;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * কাগজের সম্পর্ক — গ্রাহক, সরবরাহকারী, ক্রয়ের কাগজ, কর্মী (পরিকল্পনা §১৫; চতুর্থ ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কোনো মডিউলের ক্লাস নয় ──────────────────────────────────────────
 * DOC কেবল `master_data`-র উপর দাঁড়ায় ([[BoundariesTest]])। রেকর্ড চেনা হয় ABOS-এর ড্রিলের নাম আর id
 * দিয়ে ([[DrillResolver]]), খোঁজা হয় ABOS-এর খোঁজের নিজের তালিকা দিয়ে ([[SearchEngine::sources()]]), আর
 * "দেখতে পারেন কি না" সেই খোঁজেরই নিয়মে ([[SearchEngine::maySee()]])। ⓘ ঐ মডিউল বন্ধ থাকলে তার ধরনটা
 * নিজে থেকেই তালিকা থেকে সরে যায়।
 *
 * ⓘ উল্টো দিক — রেকর্ডের নিজের পাতায় জোড়া কাগজ — কোরের চুক্তি দিয়ে ([[LinkedDocuments]],
 * `<x-ui.linked-documents>`), যাতে ঐ মডিউলও DOC-এর ক্লাস না চেনে।
 */
final class DocumentLinks implements LinkedDocuments
{
    /**
     * পরিকল্পনা §১৫-এর রেকর্ড — মালিকের ক্রমে।
     * ⓘ RFQ-র নিজের ড্রিলের নাম ABOS-এ নেই, তাই আজ তালিকায় নেই; যেদিন হবে সেদিন এখানে একটা শব্দ।
     *
     * @var list<string>
     */
    public const TYPES = ['customer', 'supplier', 'purchase_order', 'purchase_receipt', 'purchase_bill', 'employee'];

    public function __construct(
        private readonly DrillResolver $drill,
        private readonly SearchEngine $search,
    ) {}

    /**
     * আজ যে ধরনগুলোয় জোড়া যায় — চালু মডিউলের, নাম lang থেকে।
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        $out = [];

        foreach (self::TYPES as $type) {
            if ($this->drill->knows($type)) {
                $out[$type] = __('documents::catalog.link_type.'.$type);
            }
        }

        return $out;
    }

    /**
     * খোঁজ — এই ধরনের যে রেকর্ডগুলো নামে বা নম্বরে মেলে আর মানুষটা দেখতে পান।
     *
     * @return list<array{type: string, id: int, no: string, label: string}>
     */
    public function candidates(string $type, string $term, User $user): array
    {
        $term = trim($term);
        $class = $this->drill->map()[$type] ?? null;
        $source = $class === null ? null : ($this->search->sources()[$class] ?? null);

        if ($source === null || mb_strlen($term) < 2 || ! array_key_exists($type, $this->types())) {
            return [];
        }

        $like = '%'.addcslashes($term, '\\%_').'%';

        /** @var class-string<Model> $class */
        $rows = $class::query()
            ->where(function ($q) use ($source, $like) {
                foreach ($source['columns'] as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            })
            ->limit(20)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            if ($row instanceof Drillable && $this->search->maySee($user, $row)) {
                $out[] = ['type' => $type, 'id' => (int) $row->getKey(), 'no' => $row->drillDocumentNo(), 'label' => $row->drillLabel()];
            }
        }

        return $out;
    }

    public function link(Document $document, string $type, int $id, User $user): DocumentLink
    {
        $record = array_key_exists($type, $this->types()) ? $this->drill->resolve($type, $id) : null;

        // ⛔ অন্য কোম্পানির বা না-দেখার রেকর্ড — "নেই"-এর মতোই
        if (! $record instanceof Model || ! $this->search->maySee($user, $record)) {
            throw ValidationException::withMessages(['source_id' => __('documents::message.link_not_found')]);
        }

        $link = DocumentLink::query()->firstOrCreate(
            ['document_id' => $document->getKey(), 'source_type' => $type, 'source_id' => $id],
            ['company_id' => $document->company_id ?? CompanyContext::id(), 'created_by' => Actor::userId(), 'updated_by' => Actor::userId()],
        );

        if ($link->wasRecentlyCreated) {
            $document->auditAction('document_linked', $type.' · '.$record->drillDocumentNo());
        }

        return $link;
    }

    public function unlink(Document $document, DocumentLink $link): void
    {
        $label = $link->source_type.' #'.$link->source_id;
        $link->delete();

        $document->auditAction('document_unlinked', $label);
    }

    /**
     * কাগজের পাতার জন্য — প্রতিটা জোড়া, রেকর্ডের নম্বর আর নাম আর ঠিকানাসহ, যদি দেখা যায়।
     *
     * @return list<array{link: DocumentLink, type: string, no: string, label: string, url: ?string}>
     */
    public function of(Document $document, User $user): array
    {
        $out = [];

        foreach (DocumentLink::query()->where('document_id', $document->getKey())->orderBy('id')->get() as $link) {
            $record = $this->drill->resolve($link->source_type, (int) $link->source_id);
            $visible = $record instanceof Model && $this->search->maySee($user, $record);

            $out[] = [
                'link' => $link,
                'type' => __('documents::catalog.link_type.'.$link->source_type),
                'no' => $visible ? $record->drillDocumentNo() : '#'.$link->source_id,
                'label' => $visible ? $record->drillLabel() : __('documents::message.link_hidden'),
                'url' => $visible ? route(...$record->drillRoute()) : null,
            ];
        }

        return $out;
    }

    /**
     * ⭐ উল্টো দিক — রেকর্ডের পাতায় জোড়া কাগজ ([[LinkedDocuments]])।
     * ⛔ কেবল যা এই মানুষ দেখতে পান — তিন দেয়াল আর বিভাগ সবই ([[Document::scopeVisibleTo()]])।
     */
    public function forRecord(string $sourceType, int $sourceId, User $user): array
    {
        if (! $user->can('documents.view')) {
            return [];
        }

        return Document::query()
            ->visibleTo($user)
            ->whereIn('dms_documents.id', DocumentLink::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->select('document_id'))
            ->orderByDesc('dms_documents.id')
            ->limit(50)
            ->get()
            ->map(fn (Document $d) => [
                'no' => (string) $d->document_no,
                'name' => $d->name(),
                'url' => route('documents.show', $d),
            ])
            ->all();
    }
}
