<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\ExpiryNotice;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * মেয়াদের খবর আর "মেয়াদোত্তীর্ণ" অবস্থা — ঘণ্টায় একবার, প্রতিটা কোম্পানিতে
 * (পরিকল্পনা §১২; তৃতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⭐ ধাপ ─────────────────────────────────────────────────────────────
 * মেয়াদ শেষের ৯০, ৬০, ৩০, ১৫, ৭ আর ১ দিন আগে একবার করে; পেরোলে একবার "মেয়াদ শেষ", আর কাগজের
 * অবস্থা "মেয়াদোত্তীর্ণ"। ⓘ কাগজটা প্রথম দেখা গেল ২০ দিন আগে? তখন কেবল ৩০-এর খবর — পেরিয়ে
 * যাওয়া ৯০ আর ৬০ একসাথে তিনটা খবর হয়ে আসে না।
 *
 * ── ⛔ একবারই, যতবার চলুক ───────────────────────────────────────────
 * প্রতিটা পাঠানো খবর একটা সারি ([[ExpiryNotice]], অনন্য চাবি কাগজ + মেয়াদ + ধাপ)। ⚠️ কাজটা
 * ঘণ্টায় চলে, একটা ঘণ্টা বাদ পড়লেও পরের ঘণ্টায় ধরে — আর একই খবর দুইবার যায় না।
 * ⓘ সারি বসে আগে, খবর পরে, এক লেনদেনে: দুইটা চলা একসাথে এলেও অনন্য চাবি দ্বিতীয়টাকে থামায়।
 *
 * ⓘ আজকের তারিখ PHP থেকে, ডাটাবেজের ঘড়ি থেকে নয়।
 */
final class DocumentExpiry
{
    /** @var list<int> বড় থেকে ছোট */
    public const THRESHOLDS = [90, 60, 30, 15, 7, 1];

    public function __construct(
        private readonly DocumentNotices $notices,
        private readonly DocumentWorkflow $workflow,
    ) {}

    /**
     * চলতি কোম্পানিতে একবার চলা।
     *
     * @return array{notices: int, expired: int, reviewing: int}
     */
    public function run(): array
    {
        $companyId = (int) CompanyContext::id();
        $today = Carbon::today();
        $out = ['notices' => 0, 'expired' => 0, 'reviewing' => 0];

        // ⓘ মাঝের সই পড়েছে কি না — ইঞ্জিন এর খবর ছোড়ে না ([[DocumentWorkflow::sync()]])
        foreach ($this->papers($companyId)->where('status', DocumentCatalog::SUBMITTED)->get() as $submitted) {
            $this->workflow->sync($submitted);
            $out['reviewing'] += (int) ($submitted->status === DocumentCatalog::UNDER_REVIEW);
        }

        $due = $this->papers($companyId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(self::THRESHOLDS[0])->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($due as $document) {
            $days = (int) $today->diffInDays($document->expiry_date, false);
            $threshold = $this->thresholdFor($days);

            if ($threshold === null) {
                continue;
            }

            DB::transaction(function () use ($document, $threshold, $days, $companyId, &$out) {
                $already = ExpiryNotice::query()
                    ->where('document_id', $document->id)
                    ->whereDate('expiry_date', $document->expiry_date->toDateString())
                    ->where('threshold', $threshold)
                    ->lockForUpdate()
                    ->exists();

                if ($already) {
                    return;
                }

                $notice = ExpiryNotice::query()->create([
                    'company_id' => $companyId,
                    'document_id' => $document->id,
                    'expiry_date' => $document->expiry_date->toDateString(),
                    'threshold' => $threshold,
                ]);

                /*
                 * ⛔ জমা বা পর্যালোচনায় থাকা কাগজের অবস্থা মেয়াদের রান বদলায় না (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️১৫)। ⓘ আগে
                 * "মেয়াদোত্তীর্ণ" বসিয়ে দিত, অথচ অনুমোদন ইনবক্সে ঝুলে থাকত; শেষ সইয়ের পর [[DocumentWorkflow::decided()]] মেয়াদোত্তীর্ণ
                 * কাগজকে ছুঁত না — অনুমোদন বলত "অনুমোদিত", কাগজ বলত "মেয়াদোত্তীর্ণ"। খবর আগের মতোই যায়।
                 */
                $reviewing = in_array($document->status, [DocumentCatalog::SUBMITTED, DocumentCatalog::UNDER_REVIEW], true);

                if ($threshold === 0 && $document->status !== DocumentCatalog::EXPIRED && ! $reviewing) {
                    $document->forceFill(['status' => DocumentCatalog::EXPIRED])->saveQuietly();
                    $document->auditAction('document_expired', $document->expiry_date->toDateString());
                    $out['expired']++;
                }

                $sent = $this->notices->expiry($document, $days);
                $notice->forceFill(['sent_to' => $sent])->saveQuietly();
                $out['notices']++;
            });
        }

        return $out;
    }

    /** কোন ধাপের খবর — পেরোলে ০; ৯০ দিনের বেশি বাকি থাকলে কিছুই না */
    public function thresholdFor(int $days): ?int
    {
        if ($days < 0) {
            return 0;
        }

        $hit = null;

        foreach (self::THRESHOLDS as $threshold) {
            if ($days <= $threshold) {
                $hit = $threshold;
            }
        }

        return $hit;
    }

    /**
     * ⓘ কাজটা কোনো মানুষের হয়ে চলে না — শাখা আর হেডারের দেয়াল নয়, কেবল কোম্পানি।
     * ⛔ আর্কাইভ, মোছা আর বিনের কাগজ বাদ — ওগুলোর মেয়াদ নিয়ে কেউ কিছু করবেন না।
     */
    private function papers(int $companyId): Builder
    {
        return Document::query()->withoutGlobalScopes()
            ->where('dms_documents.company_id', $companyId)
            ->whereNull('dms_documents.deleted_at')
            ->whereNull('dms_documents.archived_at');
    }
}
