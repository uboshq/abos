<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * রাখার আর আর্কাইভের নিয়ম চালানো — দিনে একবার, প্রতিটা কোম্পানিতে (§২০ Retention / Archive Policies; সপ্তম ধাপ)।
 *
 * ── ⛔ নিয়ম কখনো চিরতরে মোছে না ───────────────────────────────────────
 * নিয়মের সবচেয়ে বড় কাজ: রিসাইকেল বিনে পাঠানো — ঠিক মানুষের "মোছা"-র পথে ([[DocumentLibrary::delete()]]),
 * কে মুছল (নিয়ম), কবে, অডিটে কারণসহ। চিরতরে মোছা কেবল মানুষ, বিন থেকে, নিজের চাবিতে।
 *
 * ── ⓘ কেবল অবস্থা দেখে চলে, তাই যতবার চলুক একই ফল ─────────────────────
 * আর্কাইভ: যে কাগজ এখনো আর্কাইভে নেই, আর নিয়মের দিন পেরিয়েছে। বিন: যে কাগজ আর্কাইভে আছে, বিনে নেই, আর
 * বিনের দিন পেরিয়েছে। ⚠️ সাথে থাকা কাজ: জমা বা পর্যালোচনায় থাকা কাগজে নিয়ম হাত দেয় না — সই চলছে।
 *
 * ⓘ আজকের তারিখ PHP থেকে।
 */
final class DocumentRetention
{
    public function __construct(private readonly DocumentLibrary $library) {}

    /** @return array{archived: int, binned: int} */
    public function run(): array
    {
        $out = ['archived' => 0, 'binned' => 0];

        foreach (RetentionPolicy::query()->active()->orderBy('id')->get() as $policy) {
            if ($policy->archive_after_days !== null) {
                foreach ($this->due($policy, (int) $policy->archive_after_days)->notArchived()->get() as $document) {
                    $this->library->archive($document, __('documents::message.by_policy', ['id' => $policy->id]));
                    $out['archived']++;
                }
            }

            if ($policy->bin_after_days !== null) {
                /*
                 * ⛔ বিনে যাওয়ার দিন গোনা আর্কাইভের দিন থেকে — নিয়মের ভিত্তি (তৈরি/তারিখ/মেয়াদ) থেকে নয় (১১ অক্টোবর ২০২৬,
                 * documents রিভিউ ⚠️১৭)। ⓘ আগে তিন বছরের পুরনো কাগজ আজ হাতে আর্কাইভ করলে আজ রাতেই বিনে চলে যেত।
                 */
                foreach ($this->due($policy, (int) $policy->bin_after_days, 'dms_documents.archived_at')->onlyArchived()->get() as $document) {
                    $this->library->delete($document);
                    $document->auditAction('document_retained', __('documents::message.by_policy', ['id' => $policy->id]));
                    $out['binned']++;
                }
            }
        }

        return $out;
    }

    /** এই নিয়মের কাগজ, যাদের দিন পেরিয়েছে — কোম্পানির, শাখার দেয়াল ছাড়া (কাজটা কারও হয়ে চলে না) */
    private function due(RetentionPolicy $policy, int $days, ?string $since = null): Builder
    {
        $column = $since ?? match ($policy->basis) {
            'document_date' => 'dms_documents.document_date',
            'expiry_date' => 'dms_documents.expiry_date',
            default => 'dms_documents.created_at',
        };

        return Document::query()->withoutGlobalScopes()
            ->where('dms_documents.company_id', CompanyContext::id())
            ->whereNull('dms_documents.deleted_at')
            ->whereNotIn('dms_documents.status', ['submitted', 'under_review'])
            ->when($policy->folder, fn ($q, $folder) => $q->where('dms_documents.folder', $folder))
            ->when($policy->doc_type, fn ($q, $type) => $q->where('dms_documents.doc_type', $type))
            ->whereNotNull($column)
            ->whereDate($column, '<=', Carbon::today()->subDays($days)->toDateString())
            ->orderBy('dms_documents.id');
    }
}
