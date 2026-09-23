<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\NoticePriority;
use App\Core\Support\NoticeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * মালিকের নিজের কথা — যন্ত্রের নয়।
 *
 * ── ⓘ কেন এটা কোরে, কোনো মডিউলের ভিতরে নয় ────────────────────────────
 * নোটিশ কোনো একটা মডিউলের সম্পত্তি নয় — বিক্রয়, হিসাব, গুদাম, সবার
 * উপরের কথা। ⚠️ আর নিচের চলন্ত বারটা আঁকে কোর ([[StatusNotices]]), যে
 * কোনো মডিউলের নাম জানে না (§১৯.৭)। ⛔ মডিউলের ভিতরে রাখলে কোরকে ঐ
 * মডিউলের নাম জানতে হত — ঠিক যে তীরটা ২১ সেপ্টেম্বরে মোছা হয়েছে।
 *
 * ⓘ পর্দাগুলো তবু SystemAdmin-এ, কারণ ওটা প্রশাসকের কাজ — মডেলটা কোরে
 * থাকলেও পর্দা মডিউলে থাকতে বাধা নেই। [[BranchModule]]-এ একই বিভাজন।
 */
final class Notice extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /*
     * ⛔ `status` এই তালিকায় নেই, আর সেটা সবচেয়ে জরুরি অনুপস্থিতি।
     *
     * ⓘ অবস্থা বদলানোর একমাত্র পথ [[NoticeLifecycle]], যে বৈধ পথের
     * তালিকা মানে। ⚠️ ঘরটা এখানে রাখলে যেকোনো পর্দার
     * `update($request->all())` দিয়ে অবস্থা বদলে যেত — সংরক্ষণাগার
     * থেকে সোজা প্রকাশে, অনুমোদন না নিয়েই।
     *
     * ⛔ একই কারণে `document_no`, `published_at`, `recalled_*` আর
     * `superseded_by`-ও নেই: ওগুলো ঘটনার স্মৃতি, ফর্মের ঘর নয়।
     */
    protected $fillable = [
        'company_id', 'notice_category_id', 'type', 'priority',
        'title', 'summary', 'body',
        'starts_on', 'ends_on', 'expires_at', 'is_active', 'in_ticker',
        'read_required', 'ack_required', 'ack_deadline', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
            'in_ticker' => 'boolean',
            'read_required' => 'boolean',
            'ack_required' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'ack_deadline' => 'datetime',
            'recalled_at' => 'datetime',

            /*
             * ⓘ অবস্থা আর অগ্রাধিকার enum হয়েই ফেরে — লেখা হয়ে নয়।
             *
             * ⚠️ লেখা হলে প্রতিটা তুলনায় `'published'` লিখতে হত, আর
             * একদিন কেউ `'Published'` লিখত — তুলনাটা মিথ্যা হত, নীরবে।
             */
            'status' => NoticeStatus::class,
            'priority' => NoticePriority::class,
        ];
    }

    /** @return HasMany<NoticeRole, $this> */
    public function audience(): HasMany
    {
        return $this->hasMany(NoticeRole::class);
    }

    /**
     * ⭐ এই নোটিশটা কার দিকে তাক করা — ছয় স্তরেই।
     *
     * ⓘ [[audience()]] এর পুরনো রূপ, আর ওটা কেবল ভূমিকা চিনত।
     * ⚠️ দুইটা এখন পাশাপাশি আছে, কারণ পুরনো পথটা ([[NoticeBoard]])
     * এখনো চলছে। ⛔ একই দিনে ঘর বানানো আর পুরনো পথ ভাঙা করলে
     * ভাঙাটা ধরা পড়ত ডিপ্লয়ের পরে।
     *
     * @return HasMany<NoticeTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(NoticeTarget::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(NoticeCategory::class, 'notice_category_id');
    }

    /**
     * যে নোটিশটা একে ঢেকে দিয়েছে।
     *
     * ⓘ মোছার বদলে ঢাকা — ⚠️ সুতোটা থাকলে ছয় মাস পরেও বলা
     * যায় ভুলটা কী ছিল আর কে শুধরেছেন।
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    /** @return HasMany<NoticeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(NoticeVersion::class);
    }

    /** @return HasMany<NoticeAck, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(NoticeAck::class);
    }

    /** @return HasMany<NoticeReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(NoticeReminder::class);
    }

    /** @return HasMany<NoticeRead, $this> */
    public function reads(): HasMany
    {
        return $this->hasMany(NoticeRead::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * ⭐ আজ চোখে পড়ার মতো নোটিশগুলো।
     *
     * ── ⚠️ তিনটা শর্ত, আর তিনটাই আলাদা ──────────────────────────────
     * চালু আছে · শুরুর তারিখ এসে গেছে · শেষের তারিখ পেরোয়নি।
     *
     * ⓘ তারিখ দুইটাই খালি থাকতে পারে, আর খালি মানে "সীমা নেই" — তাই
     * `whereNull` শাখাটা দরকার। ⛔ ছাড়া তারিখ না বসানো প্রতিটা নোটিশ
     * **কোনোদিন** দেখা যেত না, আর কেউ বুঝত না কেন।
     */
    public function scopeLiveOn(Builder $query, Carbon $day): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day));
    }

    /**
     * ⛔ যে নোটিশ মানুষ দেখে ফেলেছে, সেটা আর মোছা যায় না।
     *
     * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৪ ও ৪৯ ────────────
     * *"Published Notice সরাসরি delete করা যাবে না"* — বদলে Recall ·
     * Archive · Expire · Supersede।
     *
     * ── ⚠️ কেন মডেলে, পর্দায় নয় ─────────────────────────────────────
     * ⓘ মোছার ডাক আসতে পারে অনেক পথে: তালিকার বোতাম, API, একটা
     * পরিষ্কারের কমান্ড, কিংবা কাল যোগ হওয়া কোনো পর্দা। ⛔ পাহারা
     * পর্দায় থাকলে **প্রতিটা নতুন পথ একটা করে ফাঁক**।
     *
     * ── ⓘ কেন কেবল খসড়া নয়, আরও কয়েকটা অবস্থা মোছা যায় ─────────────
     * ⚠️ যে নোটিশ কোনোদিন প্রকাশ হয়নি, তার মোছা নিয়ে কারও কিছু বলার
     * নেই — কেউ ওটা দেখেনি। ⓘ কিন্তু প্রকাশের পরের যেকোনো অবস্থা —
     * মেয়াদ শেষ, প্রত্যাহার, সংরক্ষণাগার — সবগুলোর মানে **মানুষ
     * পড়েছে**, আর ইতিহাসটা তখন খাতার সম্পত্তি।
     */
    protected static function booted(): void
    {
        static::deleting(function (self $notice): void {
            $status = $notice->status instanceof NoticeStatus
                ? $notice->status
                : NoticeStatus::tryFrom((string) $notice->status);

            /* ⓘ চেনা যায়নি এমন অবস্থা — নিরাপদ দিকে, অর্থাৎ মোছা নয় */
            if ($status === null || ! $status->wasNeverSeen()) {
                throw ValidationException::withMessages([
                    'status' => __('core.notice.published_does_not_delete', [
                        'no' => $notice->document_no ?: (string) $notice->id,
                    ]),
                ]);
            }
        });
    }

    /**
     * ⭐ এই ভূমিকাগুলোর কেউ নোটিশটা দেখবেন কি না।
     *
     * ── ⓘ কোনো ভূমিকা বাছা না থাকলে সবাই দেখেন ──────────────────────
     * ⚠️ উল্টোটা ধরলে ভূমিকা বসাতে ভুলে যাওয়া নোটিশটা **কেউই** দেখত
     * না, আর লেখক ভাবতেন পাঠানো হয়ে গেছে। ⛔ নীরবে না-পৌঁছানো নোটিশের
     * চেয়ে বেশি মানুষের কাছে পৌঁছানো নিরাপদ।
     *
     * @param  list<string>  $roles  ব্যবহারকারীর ভূমিকার নাম
     */
    public function scopeForRoles(Builder $query, array $roles): Builder
    {
        return $query->where(function (Builder $q) use ($roles): void {
            $q->whereDoesntHave('audience');

            if ($roles !== []) {
                $q->orWhereHas('audience', fn (Builder $a) => $a->whereIn('role', $roles));
            }
        });
    }
}
