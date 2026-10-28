<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একজনের সই দেওয়ার ভার অন্যের হাতে, তারিখ ধরে।
 *
 * ── ⚠️ মেয়াদ শেষ হয় নিজে থেকে, কারও মনে রাখার উপর নয় ────────────────
 * ⛔ *"চালু/বন্ধ"* সুইচ হলে ফিরে এসে বন্ধ করতে মনে রাখতে হত, আর ঠিক
 * ওই জিনিসটাই মানুষ ভোলে। ⓘ তারপর মাসের পর মাস সহকারী সই দিয়ে যান,
 * আর কেউ টের পায় না।
 */
class ApprovalDelegation extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'company_id', 'from_user_id', 'to_user_id', 'starts_on', 'ends_on',
        'modules', 'actions', 'reason', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'modules' => 'array',
            'actions' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * ⭐ আজ চালু আছে এমন ভার।
     *
     * ── ⚠️ তিনটা শর্তই দরকার ─────────────────────────────────────────
     * ⓘ হাতে বন্ধ করা হয়নি · শুরু হয়ে গেছে · শেষ হয়নি। ⛔ যেকোনো
     * একটা বাদ দিলে মেয়াদের বাইরের একটা ভার কাজ করত — আর সেটাই এই
     * গোটা জিনিসটার একমাত্র বিপদ।
     */
    public function scopeActive(Builder $query, ?string $on = null): Builder
    {
        $on = $on ?? now()->toDateString();

        return $query->whereNull('revoked_at')
            ->where('starts_on', '<=', $on)
            ->where('ends_on', '>=', $on);
    }

    public const LIVE = 'live';

    public const COMING = 'coming';

    public const ENDED = 'ended';

    /**
     * ⭐ কার্ডের লেখা — চালু, আসছে না শেষ (অনুমোদন ভাগ ৩, দফা ১৮)। ⓘ [[scopeActive()]]-এর একই তিন শর্ত, তারিখ ধরে।
     * ⛔ আগে পাতাটা `isPast()`/`isFuture()` দিয়ে মাপত — শেষ দিনের মাঝরাত "ভবিষ্যৎ" নয়, তাই শেষ দিনে চালু ভারটা চালু দেখাত না।
     */
    public function stateOn(?string $on = null): string
    {
        $on = $on ?? now()->toDateString();

        if ($this->revoked_at !== null || $this->ends_on->toDateString() < $on) {
            return self::ENDED;
        }

        return $this->starts_on->toDateString() > $on ? self::COMING : self::LIVE;
    }

    /**
     * এই ভারটা কি এই কাজটা ঢাকে?
     *
     * ⓘ খালি তালিকা মানে **সব** — *"আমার সব অনুমোদন"*। ⚠️ খালিকে
     * "কিছুই না" ধরলে ভার দেওয়ার সবচেয়ে সাধারণ ব্যবহারটাই কাজ করত না।
     */
    public function covers(string $module, string $action): bool
    {
        $modules = $this->modules ?? [];
        $actions = $this->actions ?? [];

        if ($modules !== [] && ! in_array($module, $modules, true)) {
            return false;
        }

        return $actions === [] || in_array($module.'.'.$action, $actions, true);
    }
}
