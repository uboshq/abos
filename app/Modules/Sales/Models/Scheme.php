<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা স্কিম — কী পুরস্কার, কার জন্য, কোন দুই তারিখের মাঝে।
 *
 * ── কেন হারটা এখানে নেই ──────────────────────────────────────────────
 * হার থাকে নিয়মে ([[CommissionRule]]), কারণ একই স্কিম রুটিনমাফিক SR,
 * ASM আর DSM-কে তিন হারে, চারটা বিক্রয়-স্তরে দেয়। একটা সারিতে সেটা
 * ধরে না, আর ধরানোর চেষ্টা করলে ধাপ যোগ করতে মাইগ্রেশন লাগত।
 *
 * ── কেন এটা লাগল ─────────────────────────────────────────────────────
 * ABOS-এ কমিশনের **দাবি** আগে থেকেই ছিল — কাকে কত দেওয়া হলো। কিন্তু
 * কত দেওয়ার কথা ছিল সেটা কোথাও লেখা ছিল না, তাই প্রতিবার কেউ হাতে হার
 * বসাতেন। মাস-শেষে কোম্পানির কাছে দাবি করার সময় "এই হারটা কে ঠিক
 * করল" প্রশ্নের উত্তর ছিল একজন মানুষের স্মৃতি।
 */
class Scheme extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /* ── কীসের উপর গোনা ───────────────────────────────────────────── */

    /** টাকার উপর। */
    public const VALUE = 'value';

    /** পরিমাণের উপর — বস্তা, কার্টন। */
    public const VOLUME = 'volume';

    /** যত বেশি বিক্রি তত বেশি হার; ধাপগুলো নিয়মে। */
    public const SLAB = 'slab';

    /* ── কীসের দিকে তাক করা ───────────────────────────────────────── */

    public const ALL = 'all';

    public const PRODUCT = 'product';

    public const CATEGORY = 'category';

    public const BRAND = 'brand';

    public const TERRITORY = 'territory';

    public const DEALER_TIER = 'dealer_tier';

    /* ── অবস্থা ───────────────────────────────────────────────────── */

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $table = 'sal_schemes';

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name',
        'basis', 'applies_to', 'target_id',
        'valid_from', 'valid_to', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এই দিনে স্কিমটা টাকা দিতে পারে কি না।
     *
     * ── কেন তিনটা শর্তই এক জায়গায় ──────────────────────────────────
     * "চালু আছে" আর "আজ মেয়াদের ভেতরে" — দুইটা আলাদা প্রশ্ন, আর
     * দুইটা আলাদা জায়গায় জিজ্ঞেস করলে একদিন একটা জিজ্ঞেস করা হত আর
     * অন্যটা নয়। মেয়াদোত্তীর্ণ স্কিম তখনো টাকা দিত।
     */
    public function isLiveOn(Carbon|string $date): bool
    {
        if ($this->status !== self::ACTIVE) {
            return false;
        }

        $on = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $this->valid_from !== null
            && $this->valid_to !== null
            && ! $on->lt($this->valid_from)
            && ! $on->gt($this->valid_to);
    }

    /**
     * এই দিনে যে স্কিমগুলো টাকা দিতে পারে।
     *
     * তারিখটা SQL-এই দেখা হয়, কোডে নয় — প্রতিটা বিলে এই প্রশ্নটা করা
     * হয়, আর সব স্কিম টেনে এনে কোডে ছাঁকলে বছরের পুরনো স্কিমগুলোও
     * প্রতিবার মেমরিতে উঠত।
     */
    public function scopeLiveOn(Builder $query, Carbon|string $date): Builder
    {
        $on = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query->where('status', self::ACTIVE)
            ->whereDate('valid_from', '<=', $on)
            ->whereDate('valid_to', '>=', $on);
    }

    /**
     * মেয়াদ পেরিয়ে গেছে অথচ এখনো চালু বলে বসে আছে।
     *
     * ── কেন এটা আলাদা করে জানা দরকার ────────────────────────────────
     * `isLiveOn()` তারিখ দেখে বলে দেয় টাকা দেবে না, তাই হিসাবে ভুল
     * হয় না। কিন্তু তালিকায় সারিটা "চালু" লেখা থাকে, আর কেউ ধরে নেন
     * স্কিমটা এখনো চলছে — তারপর গ্রাহককে সেই কথা দিয়ে বসেন।
     */
    public function hasLapsed(): bool
    {
        return $this->status === self::ACTIVE
            && $this->valid_to !== null
            && $this->valid_to->isBefore(now()->startOfDay());
    }
}
