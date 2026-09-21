<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা নগদ কাউন্টার — একজনের হেফাজতে থাকা টাকা।
 *
 * ব্যালেন্স এই মডেলে সংরক্ষিত নয়, লেজার থেকে গোনা হয়। কারণ একটাই:
 * দুই জায়গায় একই সংখ্যা রাখলে একদিন সেগুলো আলাদা হবে, আর তখন কোনটা
 * সত্যি তা কেউ বলতে পারবে না। টাকার হিসাবে সেটা মেনে নেওয়ার মতো নয়।
 */
class CashTill extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasDocumentStatus;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'account_id', 'code',
        'name_en', 'name_bn', 'holder_id', 'limit_amount',
        'is_primary', 'status', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'limit_amount' => 'decimal:4',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function name(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->name_bn)) {
            return $this->name_bn;
        }

        return $this->name_en;
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * এই নগদ খাতে এই মানুষটা টাকা নিতে পারেন কি না।
     *
     * ── ⭐ মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬ ────────────────────────
     * *"cash e sudu tar nijer cash accounts e taka nite parbe"* — আর
     * সেটাই নগদে অনুমোদন তুলে দেওয়ার শর্ত।
     *
     * ── ⛔ নিয়মটা ঘুমিয়ে থাকে, আর কারণটা মাপা ───────────────────────
     * প্রথম দিন নিয়মটা কড়া করে বসানো হয়েছিল, আর লাইভে **মালিক নগদই
     * বাছতে পারলেন না**: তিনটা ক্যাশবাক্সের একটারও `holder_id` বসানো
     * ছিল না, তাই "নিজের বাক্স" বলে কিছুই মিলত না আর ড্রপডাউনে কেবল
     * ব্যাংক থাকত।
     *
     * ⚠️ একটা পাহারা যা কাজটাই বন্ধ করে দেয়, সেটা পাহারা নয় — কয়েক
     * দিনেই কেউ ওটা তুলে দেয়। ⭐ তাই নিয়মটা **তখনই জাগে যখন ব্যবসা
     * সেটা ব্যবহার শুরু করে**: অন্তত একটা বাক্স কারও নামে বসলে।
     *
     * ⓘ কারও নামে বসানোর আগে আচরণ আগের মতোই — যেকোনো নগদ খাত।
     * ⓘ বসানোর পর: যাঁর বাক্স আছে কেবল তাঁর বাক্স, আর যাঁর নেই তিনি
     * নগদ নিতে পারবেন না (তখন টাকাটা কার হেফাজতে তার উত্তর থাকে না)।
     */
    public static function mayUse(?int $userId, int $accountId): bool
    {
        if (! self::heldByAnyone()) {
            return true;
        }

        if ($userId === null) {
            return true;
        }

        return self::query()
            ->active()
            ->heldBy($userId)
            ->where('account_id', $accountId)
            ->exists();
    }

    /**
     * কোম্পানিতে একটাও বাক্স কারও নামে বসানো আছে কি না।
     *
     * ⓘ এটাই সুইচ: না বসা থাকলে গোটা নিয়মটা ঘুমিয়ে থাকে।
     * ⚠️ কনসোল ও সিডারে প্রসঙ্গ থাকে না, আর তখন স্কোপ কিছুই ফেরায় না —
     * সেটাই ঠিক, কারণ ওখানে কোনো ব্যবহারকারীও নেই।
     */
    public static function heldByAnyone(): bool
    {
        return self::query()->active()->whereNotNull('holder_id')->exists();
    }

    /** যে টিলগুলো এই ব্যবহারকারীর হেফাজতে। */
    public function scopeHeldBy(Builder $query, int $userId): Builder
    {
        return $query->where('holder_id', $userId);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('code', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('name_bn', 'like', $like);
        });
    }

    /** এই মুহূর্তে হাতে কত — লেজার থেকে। */
    public function balance(?string $upto = null): string
    {
        return $this->account->balanceOn($upto);
    }

    /**
     * সীমা ছাড়িয়ে গেছে কি না।
     *
     * শূন্য মানে সীমাহীন, বন্ধ নয় — নতুন একটা টিলের প্রথম আদায়টাই
     * নাহলে "সীমা ছাড়িয়েছে" বলত।
     */
    public function isOverLimit(): bool
    {
        if (bccomp((string) $this->limit_amount, '0', 4) === 0) {
            return false;
        }

        return bccomp($this->balance(), (string) $this->limit_amount, 4) > 0;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'cash_till';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['accounts.till.show', ['till' => $this->id]];
    }
}
