<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ListedInViewedBranch;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\ShowsItselfForSigning;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Documents\Services\DocumentAccess;
use App\Modules\Documents\Services\DocumentChoices;
use App\Modules\Documents\Support\DocumentCatalog;
use App\Modules\MasterData\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা ডকুমেন্ট — তাকের কাগজ (§১, §২১; ৮ অক্টোবর ২০২৬)।
 *
 * ⓘ ফাইল এখানে নেই — প্রতিটা ফাইল একটা ভার্সন ([[DocumentVersion]]), আর ফাইলটা
 * নিজে ABOS-এর পুরনো সংযুক্তির খাতায় ([[Attachment]])। এই সারি কেবল বলে কাগজটা
 * কী, কার, কোথায়, কতদিন, আর কতটা গোপন।
 *
 * ── ⛔ তিনটা দেয়াল, তিনটাই এখানে ─────────────────────────────────────
 * কোম্পানি ([[BelongsToCompany]]), শাখা ([[ScopedToUserBranch]]) — দুইটাই গ্লোবাল
 * স্কোপ, তাই ঠিকানায় অন্যের id লিখলে ৪০৪। ⭐ গোপনীয়তা গ্লোবাল স্কোপ নয়, কারণ
 * অডিট আর ড্যাশবোর্ডের গোনা ধাপ না দেখেও কাগজ চেনে; তাই সেটা দুই জায়গায়:
 * তালিকার [[scopeVisibleTo()]] আর ঠিকানা থেকে কাগজ তোলার [[resolveRouteBinding()]]।
 */
class Document extends Model implements ShowsItselfForSigning
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ListedInViewedBranch;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'dms_documents';

    /**
     * ⛔ নাম ফাঁকা নতুন কাগজেও ঘরটা থাকে — নাহলে `$document->name` পড়তে গিয়ে Eloquent
     * `name()`-কে (অডিটের লেবেল) সম্পর্ক ভেবে ডাকত, আর আপলোডের ফর্ম ৫০০ দিত।
     */
    protected $attributes = ['name' => null];

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'name', 'doc_type', 'folder',
        'department_id', 'owner_id', 'document_date', 'expiry_date', 'confidentiality',
        'tags', 'description', 'status', 'current_version_id',
        'archived_at', 'archived_by', 'archived_from_status', 'deleted_from_status',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'expiry_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * ⓘ যন্ত্রের হিসাব অডিটে যায় না — চলতি ভার্সনের id বদলায় প্রতিটা নতুন ভার্সনে,
     * আর সেই ঘটনা নিজের নামে আলাদা লেখা থাকে (`document_version_added`)।
     *
     * @return list<string>
     */
    public function auditIgnores(): array
    {
        return ['current_version_id', 'updated_by'];
    }

    /** অডিটের লেবেল — কাগজের নাম ([[AuditEngine::labelFor()]]) */
    public function name(): string
    {
        // ⛔ getAttribute নয় — নাম ফাঁকা থাকলে Eloquent `name()`-কে সম্পর্ক ভেবে আবার এখানেই ডাকত (অসীম লুপ)
        return (string) ($this->attributes['name'] ?? '');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(DocumentGrant::class, 'document_id');
    }

    public function metadata(): HasMany
    {
        return $this->hasMany(DocumentMetadata::class, 'document_id');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** ⛔ অনুমোদিত কাগজ নিজের জায়গায় বদলায় না — বদল মানে নতুন ভার্সন (§৯) */
    public function isApproved(): bool
    {
        return in_array($this->status, DocumentCatalog::SEALED, true);
    }

    /** ⓘ বিবরণ নিজের জায়গায় বদলানো যায় এমন অবস্থা ([[DocumentCatalog::EDITABLE]]) */
    public function isEditableInPlace(): bool
    {
        return in_array($this->status, DocumentCatalog::EDITABLE, true);
    }

    /** মেয়াদ পেরিয়েছে কি না — আজকের তারিখ PHP থেকে, ডাটাবেজ থেকে নয় */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lt(Carbon::today());
    }

    /** @return list<string> ট্যাগগুলো, ফাঁকা বাদে */
    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tags))));
    }

    /**
     * এই মানুষ যে কাগজগুলো দেখতে পান — ধাপ আর বিভাগ, নিজের কাগজ, নয়তো কাগজ-ধরে অধিকার
     * ([[DocumentAccess::visibleScope()]])।
     *
     * ⚠️ কোম্পানি আর শাখার দেয়াল গ্লোবাল স্কোপে আগেই বসে; এটা তৃতীয় দেয়াল।
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(DocumentAccess::class)->visibleScope($query, $user);
    }

    /** সেন্টারে কেবল চলতি কাগজ — আর্কাইভ করা সরে যায়, মোছে না */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull($this->getTable().'.archived_at');
    }

    public function scopeOnlyArchived(Builder $query): Builder
    {
        return $query->whereNotNull($this->getTable().'.archived_at');
    }

    /**
     * ঠিকানা থেকে কাগজ — তিন দেয়ালের ভিতরে, নইলে ৪০৪।
     *
     * ⛔ কেবল কোম্পানি আর শাখার স্কোপে ছাড়লে একটা গোপন কাগজের id জানা মানুষ
     * অন্তত জানতেন কাগজটা আছে (৪০৩ বনাম ৪০৪)। ⭐ এখানে গোপনীয়তাও বসে, তাই না-দেখার
     * কাগজ আর না-থাকা কাগজ ঠিকানায় একই রকম দেখায়। পলিসিও আবার দেখে (দ্বিতীয় তালা)।
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $user = auth()->user();

        $query = $this->resolveRouteBindingQuery($this->newQuery(), $value, $field);

        if (! $user instanceof User) {
            return null;
        }

        $visible = (clone $query)->visibleTo($user)->first();

        if ($visible !== null) {
            return $visible;
        }

        // ⭐ সইকারী — তালিকার দেয়ালের বাইরে, কিন্তু সইয়ের অপেক্ষায় তাঁর সামনে ([[DocumentAccess::isSigner()]])
        $waiting = $query->whereIn($this->getTable().'.status', [DocumentCatalog::SUBMITTED, DocumentCatalog::UNDER_REVIEW])->first();

        return $waiting !== null && app(DocumentAccess::class)->isSigner($user, $waiting) ? $waiting : null;
    }

    /**
     * ⓘ ছাপের বাইরে ([[DocumentFingerprint]]) — কে শেষ ছুঁয়েছেন, আর্কাইভ/মোছার হিসাব। কাগজের কথা
     * নয়; ধরলে প্রতিটা আর্কাইভে সই অচল হত।
     *
     * @return list<string>
     */
    public function fingerprintIgnores(): array
    {
        return ['updated_by', 'archived_at', 'archived_by', 'archived_from_status',
            'deleted_by', 'deleted_from_status', 'deleted_at'];
    }

    /**
     * সইয়ের পাতায় কাগজটা নিজে কী বলে (§১০) — নম্বর, ধরন, গোপনীয়তা, ভার্সন আর ⭐ ফাইলের SHA-256:
     * সইকারী জানেন ঠিক কোন বাইটে সই দিচ্ছেন। নিচে ভার্সনগুলো, নতুনটা আগে।
     */
    public function signingSheet(): array
    {
        $choices = app(DocumentChoices::class);
        $current = $this->currentVersion;

        return [
            'facts' => array_values(array_filter([
                ['label' => __('documents::field.document_no'), 'value' => (string) $this->document_no],
                ['label' => __('documents::field.name'), 'value' => $this->name()],
                ['label' => __('documents::field.doc_type'), 'value' => $choices->typeName($this->doc_type)],
                ['label' => __('documents::field.folder'), 'value' => $choices->folderName($this->folder)],
                ['label' => __('documents::field.confidentiality'), 'value' => (string) __('documents::catalog.level.'.$this->confidentiality)],
                ['label' => __('documents::field.version'), 'value' => $current ? 'v'.$current->label() : ''],
                ['label' => __('documents::field.file_hash'), 'value' => (string) ($current?->file_hash ?? '')],
                ['label' => __('documents::field.expiry_date'), 'value' => (string) ($this->expiry_date?->toDateString() ?? '')],
            ], fn (array $f) => $f['value'] !== '')),
            'columns' => [
                ['key' => 'version', 'label' => __('documents::field.version')],
                ['key' => 'author', 'label' => __('documents::field.author')],
                ['key' => 'comment', 'label' => __('documents::field.comment')],
            ],
            'rows' => $this->versions()->with('author')->orderByDesc('major')->orderByDesc('minor')->limit(10)->get()
                ->map(fn (DocumentVersion $v) => [
                    'version' => 'v'.$v->label(),
                    'author' => $v->author?->name,
                    'comment' => $v->comment,
                ])->all(),
        ];
    }

    // ── ইনবক্সের লিংক (ড্রিলের নাম নয় — [[DocumentLibrary::ENTITY]]) ─────────────

    public function drillDocumentNo(): string
    {
        return (string) $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    /** @return array{0: string, 1: array<string, int>} */
    public function drillRoute(): array
    {
        return ['documents.show', ['document' => (int) $this->getKey()]];
    }
}
