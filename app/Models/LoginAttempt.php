<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা ঢোকার চেষ্টা — সফল হোক বা না হোক।
 *
 * ── কেন ব্যর্থগুলোও ─────────────────────────────────────────────────
 * সফল ঢোকা রোজকার ঘটনা। যেটা কেউ দেখে না অথচ দেখা উচিত, সেটা একই নামে
 * পঁচিশটা ব্যর্থ চেষ্টা এক ঘণ্টায় — ওটাই একমাত্র চিহ্ন যে কেউ পাসওয়ার্ড
 * আন্দাজ করছে।
 */
class LoginAttempt extends Model
{
    use HasPublicId;

    protected $table = 'login_history';

    /** একটা চেষ্টা সম্পাদনা হয় না — কেবল ঘটার সময়টা। */
    public const UPDATED_AT = null;

    /** নামটাই চেনা যায়নি — কেউ ব্যবহারকারীর তালিকা আন্দাজ করছে। */
    public const UNKNOWN = 'unknown';

    /** নাম ঠিক, পাসওয়ার্ড ভুল — কেউ পাসওয়ার্ড আন্দাজ করছে। */
    public const WRONG_PASSWORD = 'password';

    /** অ্যাকাউন্ট বন্ধ — সরানো কর্মী এখনো চেষ্টা করছেন। */
    public const INACTIVE = 'inactive';

    /**
     * পাসওয়ার্ড ঠিক, কোড দেওয়া হয়নি।
     *
     * সাধারণত এটা নিরীহ — লগইনের পাতা দুই ধাপে, তাই প্রথম ধাপে কোড
     * থাকেই না। কিন্তু একই নামে বারবার এটা মানে কেউ পাসওয়ার্ডটা
     * পেয়ে গেছেন আর কোডে আটকে আছেন, আর সেটা জানা সবচেয়ে জরুরি।
     */
    public const NEEDS_CODE = 'needs_code';

    /** পাসওয়ার্ড ঠিক, কোড ভুল — উপরেরটার চেয়েও জোরালো চিহ্ন। */
    public const WRONG_CODE = 'wrong_code';

    protected $fillable = [
        'company_id', 'user_id', 'identifier', 'succeeded',
        'reason', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * কে চেষ্টা করেছিল — চেনা গেলে নাম, নাহলে যা টাইপ করা হয়েছিল।
     *
     * অচেনা নামটাই দেখানো হয়, কারণ সেটাই একমাত্র সূত্র: কেউ `admin`
     * বা `owner` লিখে চেষ্টা করছে কি না, ওই লেখাটা দেখেই বোঝা যায়।
     */
    public function who(): string
    {
        return $this->user?->name ?? $this->identifier;
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('succeeded', false);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
