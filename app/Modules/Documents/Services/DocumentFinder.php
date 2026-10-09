<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Models\AuditTrail;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * ডকুমেন্ট সেন্টারের খোঁজ — তিন তালিকা (সেন্টার, আমার, সাম্প্রতিক), এক ছাঁকনি (§৪, §১২; ৮ অক্টোবর ২০২৬)।
 *
 * ⛔ প্রতিটা তালিকা একই গোড়া থেকে ([[base()]]): কোম্পানি আর শাখার দেয়াল (গ্লোবাল স্কোপ),
 * হেডারে বাছা শাখা ([[ListedInViewedBranch]]), আর গোপনীয়তা ([[Document::scopeVisibleTo()]])।
 * ⚠️ তিনটা তালিকা তিনবার নিজে লিখলে একদিন একটায় গোপনীয়তার ছাঁকনি বাদ পড়ত।
 */
final class DocumentFinder
{
    /** সেন্টারের তিনটা দৃশ্য */
    public const CENTER = 'center';

    public const MINE = 'mine';

    public const RECENT = 'recent';

    /** ⭐ দ্বিতীয় ধাপ — আর্কাইভের পর্দা, রিসাইকেল বিন আর বিস্তারিত খোঁজ (§১৬, §১৯) */
    public const ARCHIVE = 'archive';

    public const BIN = 'recycle';

    public const SEARCH = 'search';

    /** ⭐ চতুর্থ ধাপ — আমার সাথে শেয়ার করা (§১৪) */
    public const SHARED = 'shared';

    /** ⭐ তৃতীয় ধাপ — মেয়াদ ও নবায়ন (§১২) */
    public const EXPIRY = 'expiry';

    /**
     * ছাঁকনিসহ তালিকার কোয়েরি — পাতা ভাগ আর সাজানো কন্ট্রোলারে।
     *
     * @param  array<string, mixed>  $filters  q, folder, doc_type, department_id, confidentiality, expiry, archived
     */
    public function query(User $user, string $view, array $filters): Builder
    {
        $query = $this->base($user)
            ->with(['owner:id,name', 'deleter:id,name', 'currentVersion', 'department']);

        if ($view === self::MINE) {
            $query->where(fn (Builder $q) => $q
                ->where('dms_documents.owner_id', $user->getKey())
                ->orWhere('dms_documents.created_by', $user->getKey()));
        }

        if ($view === self::RECENT) {
            $this->recentFor($query, $user);
        }

        if ($view === self::SHARED) {
            app(DocumentAccess::class)->sharedWith($query, $user);
        }

        if ($view === self::EXPIRY) {
            // ⓘ ছাঁকনি না বাছলে: পেরোনো আর ৯০ দিনের মধ্যে — সবচেয়ে কাছেরটা আগে
            if (! in_array((string) ($filters['expiry'] ?? ''), DocumentCatalog::EXPIRY_WINDOWS, true)) {
                $query->whereNotNull('dms_documents.expiry_date')
                    ->whereDate('dms_documents.expiry_date', '<=', Carbon::today()->addDays(90)->toDateString());
            }

            $query->orderBy('dms_documents.expiry_date')->orderBy('dms_documents.id');
        }

        /*
         * ⭐ আর্কাইভ সেন্টার থেকে সরে; "আর্কাইভ করা" টিক বা আর্কাইভের পর্দায় কেবল সেগুলোই।
         * ⓘ বিনে কেবল মোছা কাগজ; বিস্তারিত খোঁজে আর্কাইভসহ সব (মোছা ছাড়া) — খোঁজের মানুষ
         * জানেন না কাগজটা কোথায় সরেছে।
         */
        match (true) {
            $view === self::BIN => $query->onlyTrashed(),
            $view === self::ARCHIVE, (bool) ($filters['archived'] ?? false) => $query->onlyArchived(),
            $view === self::SEARCH => null,
            default => $query->notArchived(),
        };

        $this->search($query, (string) ($filters['q'] ?? ''));

        $choices = app(DocumentChoices::class);

        foreach (['folder' => array_keys($choices->folders(true)), 'doc_type' => array_keys($choices->types(true)),
            'confidentiality' => DocumentCatalog::LEVELS, 'status' => DocumentCatalog::STATUSES] as $column => $allowed) {
            $value = (string) ($filters[$column] ?? '');

            if (in_array($value, $allowed, true)) {
                $query->where('dms_documents.'.$column, $value);
            }
        }

        foreach (['department_id', 'branch_id', 'owner_id'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where('dms_documents.'.$column, (int) $filters[$column]);
            }
        }

        $this->expiring($query, (string) ($filters['expiry'] ?? ''));
        $this->advanced($query, $filters);

        return $query;
    }

    /**
     * ফোল্ডার ধরে গোনা — ট্যাবের সংখ্যা, একই দেয়াল আর গোপনীয়তার ভিতরে।
     *
     * ⓘ `GROUP BY folder` আর বাছাইয়েও কেবল `folder` আর গোনা — ONLY_FULL_GROUP_BY-তে বৈধ।
     *
     * @return array<string, int>
     */
    public function folderCounts(User $user, bool $archived): array
    {
        $query = $this->base($user);
        $archived ? $query->onlyArchived() : $query->notArchived();

        return $query->toBase()
            ->select('dms_documents.folder')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('dms_documents.folder')
            ->pluck('aggregate', 'folder')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** তিন দেয়ালের ভিতরের গোড়া */
    private function base(User $user): Builder
    {
        return Document::query()->inViewedBranch()->visibleTo($user);
    }

    /**
     * সাম্প্রতিক — যে কাগজগুলো আমি খুলেছি বা বদলেছি, শেষেরটা আগে।
     *
     * ⭐ নতুন কোনো টেবিল নয়: প্রতিটা দেখা, প্রিভিউ, নামানো আর বদল অডিটে লেখা থাকে
     * ([[DocumentLibrary]]), তাই "সাম্প্রতিক" ঐ খাতা থেকেই পড়া।
     */
    private function recentFor(Builder $query, User $user): void
    {
        $mine = fn () => AuditTrail::query()
            ->where('audit_trails.auditable_type', Document::class)
            ->where('audit_trails.user_id', $user->getKey());

        $query->whereIn('dms_documents.id', $mine()->select('audit_trails.auditable_id'))
            ->orderByDesc(
                $mine()->whereColumn('audit_trails.auditable_id', 'dms_documents.id')
                    ->selectRaw('max(audit_trails.created_at)')
            );
    }

    private function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '\\%_').'%';

        $query->where(fn (Builder $q) => $q
            ->where('dms_documents.name', 'like', $like)
            ->orWhere('dms_documents.document_no', 'like', $like)
            ->orWhere('dms_documents.tags', 'like', $like)
            ->orWhere('dms_documents.description', 'like', $like));
    }

    /**
     * বিস্তারিত খোঁজের বাকি ছাঁকনি (§১৬) — তারিখের সীমা, ভার্সন, ট্যাগ, আর লেখা।
     *
     * ⓘ "লেখা" (content) — নাম আর বিবরণের সাথে প্রতিটা ভার্সনের মন্তব্য; OCR-এর লেখা এলে
     * (পঞ্চম ধাপ) সেটাও এখানেই যোগ হবে। ⓘ LIKE, কারণ কাগজ প্রতি কোম্পানিতে কয়েক হাজার —
     * আর FULLTEXT একই লেনদেনে লেখা সারি দেখে না, তাই "এইমাত্র তোলা কাগজ খুঁজে পাই না" হত।
     *
     * @param  array<string, mixed>  $filters
     */
    private function advanced(Builder $query, array $filters): void
    {
        $from = $this->date($filters['date_from'] ?? null);
        $to = $this->date($filters['date_to'] ?? null);

        if ($from !== null) {
            $query->whereDate('dms_documents.document_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('dms_documents.document_date', '<=', $to);
        }

        if (filled($filters['version'] ?? null) && ctype_digit((string) $filters['version'])) {
            // ⓘ মূল ভার্সন — "২" মানে চলতি ভার্সন v2.x
            $query->whereHas('currentVersion', fn (Builder $v) => $v->where('major', (int) $filters['version']));
        }

        $tag = trim((string) ($filters['tag'] ?? ''));

        if ($tag !== '') {
            $query->where('dms_documents.tags', 'like', '%'.addcslashes($tag, '\\%_').'%');
        }

        $content = trim((string) ($filters['content'] ?? ''));

        if ($content !== '') {
            $like = '%'.addcslashes($content, '\\%_').'%';

            $query->where(fn (Builder $q) => $q
                ->where('dms_documents.name', 'like', $like)
                ->orWhere('dms_documents.description', 'like', $like)
                ->orWhereHas('versions', fn (Builder $v) => $v->where('comment', 'like', $like))
                ->orWhereHas('metadata', fn (Builder $m) => $m->where('value', 'like', $like)));
        }
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * মেয়াদের জানালা (§১২) — `expired` মানে আজকের আগে; ৭/৩০/৯০ মানে আজ থেকে ততদিনের মধ্যে।
     *
     * ⓘ আজকের তারিখ PHP থেকে, ডাটাবেজের ঘড়ি থেকে নয় ([[NobodyAsksTheDatabaseWhatDayItIsTest]])।
     */
    private function expiring(Builder $query, string $window): void
    {
        if (! in_array($window, DocumentCatalog::EXPIRY_WINDOWS, true)) {
            return;
        }

        $today = Carbon::today();

        if ($window === 'expired') {
            $query->whereNotNull('dms_documents.expiry_date')
                ->whereDate('dms_documents.expiry_date', '<', $today->toDateString());

            return;
        }

        $query->whereNotNull('dms_documents.expiry_date')
            ->whereDate('dms_documents.expiry_date', '>=', $today->toDateString())
            ->whereDate('dms_documents.expiry_date', '<=', $today->copy()->addDays((int) $window)->toDateString());
    }
}
