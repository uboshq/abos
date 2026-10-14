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

        /*
         * ⛔ শেষ ধাপের খবর (মেয়াদ পেরোনো, ধাপ ০) একবার গেলে কাগজটা আর প্রতি ঘণ্টায় পড়া হয় না (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️১৮)।
         * ⓘ আগে নিচের সীমা ছিল না — মেয়াদ পেরোনো প্রতিটা কাগজ প্রতি ঘণ্টায় তোলা হত, প্রত্যেকটার নিজের ট্রানজ্যাকশন আর FOR UPDATE সহ,
         * আর খরচটা চিরকাল বাড়ত। একই মেয়াদে নতুন তারিখ (নবায়ন) বসলে খবরগুলো আবার শুরু হয়, কারণ শর্তটা তারিখ ধরে।
         * ⓘ উৎস কেবল ডকুমেন্টের নিজের টেবিল (`dms_documents`), পুরনো জোড়া ফাইল (`attachments`) নয় — লাইভে প্রথম রানে টেবিলটা খালি,
         * তাই "আগে থেকে মেয়াদ পেরোনো" কাগজের ইমেইল-বন্যা হয় না ([[AnExpiringDocumentIsToldOnceAtEachStepTest]])।
         */
        $due = $this->papers($companyId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(self::THRESHOLDS[0])->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('dms_expiry_notices as n')
                ->whereColumn('n.document_id', 'dms_documents.id')
                ->whereColumn('n.expiry_date', 'dms_documents.expiry_date')
                ->where('n.threshold', 0))
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
                if ($threshold === 0 && $this->expireIfFree($document)) {
                    $out['expired']++;
                }

                $sent = $this->notices->expiry($document, $days);
                $notice->forceFill(['sent_to' => $sent])->saveQuietly();
                $out['notices']++;
            });
        }

        /*
         * ⛔ পর্যালোচনার মাঝে মেয়াদ পেরোনো কাগজ — খবরটা তখনই গেছে (ধাপ ০), তাই উপরের তালিকায় আর আসে না; সিদ্ধান্তের পরে এখানে
         * "মেয়াদোত্তীর্ণ" হয় (Documents লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[ADocumentThatExpiredInReviewExpiresAfterwardsTest]])।
         * ⓘ আগে চিরকাল "অনুমোদিত" থেকে যেত — নবায়নের পথ (কেবল মেয়াদোত্তীর্ণ কাগজে) কোনোদিন খুলত না।
         */
        $late = $this->papers($companyId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today->toDateString())
            ->whereNotIn('status', [DocumentCatalog::EXPIRED, DocumentCatalog::SUBMITTED, DocumentCatalog::UNDER_REVIEW])
            ->orderBy('id')
            ->get();

        foreach ($late as $document) {
            if (DB::transaction(fn (): bool => $this->expireIfFree($document))) {
                $out['expired']++;
            }
        }

        return $out;
    }

    /**
     * ⛔ মেয়াদোত্তীর্ণ বসানো — তালায় আবার পড়ে: এর মাঝে জমা পড়লে (বা আগেই মেয়াদোত্তীর্ণ হলে) কিছু নয় (Documents লাইন-ধরে নিরীক্ষা,
     * ১৪ অক্টোবর ২০২৬)। ⓘ আগে আগের পড়া সারিতেই লিখত — পড়া আর লেখার মাঝে জমা পড়লে সেটা মুছে যেত।
     */
    private function expireIfFree(Document $document): bool
    {
        $fresh = Document::query()->withoutGlobalScopes()->whereKey($document->getKey())->lockForUpdate()->first();

        if ($fresh === null || in_array($fresh->status, [DocumentCatalog::EXPIRED, DocumentCatalog::SUBMITTED, DocumentCatalog::UNDER_REVIEW], true)) {
            return false;
        }

        $fresh->forceFill(['status' => DocumentCatalog::EXPIRED])->saveQuietly();
        $fresh->auditAction('document_expired', $fresh->expiry_date->toDateString());

        return true;
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
