<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    protected $fillable = [
        'company_id', 'title', 'body',
        'starts_on', 'ends_on', 'is_active', 'in_ticker', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
            'in_ticker' => 'boolean',
        ];
    }

    /** @return HasMany<NoticeRole, $this> */
    public function audience(): HasMany
    {
        return $this->hasMany(NoticeRole::class);
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
