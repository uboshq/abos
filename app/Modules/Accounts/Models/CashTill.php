<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Support\ViewedBranch;
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

    /**
     * ⭐ হেডারে এক শাখা বাছা থাকলে কেবল সেই শাখার টিল — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬:
     * *"প্রতিটা শাখা পুরোপুরি আলাদা"*। "সব শাখা"-য় আগের মতো সব।
     *
     * ⓘ শাখা পেরোনো হস্তান্তর কেবল "সব শাখা"-য় বসানো যায় — এক শাখার দেখায় অন্য শাখার টিল
     * নেই। ⚠️ হস্তান্তরের কাগজ দুই দিকের টিল নাম ধরে দেখায় ([[MoneyTransfer::fromTill()]]),
     * তাই সেই সম্পর্ক দেয়ালের বাইরে। টাকা বেরোনোর যাচাই নিজের টিলেই চলে।
     * ⛔ যাচাইয়ের পথ — কোড খালি কি না, প্রধান টিল, হেফাজতের নিয়ম, মাস-শেষের তালিকা — গোটা
     * কোম্পানি পড়ে, দেয়ালের বাইরে।
     */
    protected static function booted(): void
    {
        static::addGlobalScope('viewed-branch', function (Builder $builder): void {
            $branch = ViewedBranch::one();

            if ($branch !== null) {
                $builder->where($builder->getModel()->getTable().'.branch_id', $branch);
            }
        });
    }

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
        // ⓘ টিলের নিজের খাত সবসময় খুঁজে পাওয়া চাই — অন্য শাখার হস্তান্তর গ্রহণেও ([[Account::booted()]])
        return $this->belongsTo(Account::class)->withoutGlobalScope('viewed-branch-till');
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
        if ($userId === null) {
            return true;
        }

        /*
         * ⭐ খাতের নিজের হেফাজত আগে — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ অফিসের নগদ খাত বাক্স ছাড়াই কারও নামে বসানো যায়
         * (`accounts.held_by`, ১২ নভেম্বরের মাইগ্রেশন)। ⛔ আগে এখানে কেবল
         * বাক্স দেখা হত, তাই নিজের নামের অফিস-নগদও "অন্যের" ধরা পড়ত আর
         * তালিকা থেকে উধাও হত। ⚠️ নিয়মটা ঢিলে হয়নি: অন্যের নামের খাত আগের
         * মতোই বন্ধ, আর নিজের নামেরটাই কেবল খোলে।
         */
        $heldBy = Account::query()->whereKey($accountId)->value('held_by');

        if ($heldBy !== null) {
            return (int) $heldBy === $userId;
        }

        if (! self::heldByAnyone()) {
            return true;
        }

        return self::query()->withoutGlobalScope('viewed-branch')
            ->active()
            ->heldBy($userId)
            ->where('account_id', $accountId)
            ->exists();
    }

    /**
     * কোম্পানিতে একটাও বাক্স বা নগদ খাত কারও নামে বসানো আছে কি না।
     *
     * ⓘ এটাই সুইচ: না বসা থাকলে গোটা নিয়মটা ঘুমিয়ে থাকে।
     * ⚠️ কনসোল ও সিডারে প্রসঙ্গ থাকে না, আর তখন স্কোপ কিছুই ফেরায় না —
     * সেটাই ঠিক, কারণ ওখানে কোনো ব্যবহারকারীও নেই।
     * ⓘ নামে বসানো অফিস-নগদও (`held_by`) নিয়মটা জাগায় — হেফাজতের ধারণা
     * শুরু হয়েছে মানেই নিয়মটা চালু।
     */
    public static function heldByAnyone(): bool
    {
        return self::query()->withoutGlobalScope('viewed-branch')->active()->whereNotNull('holder_id')->exists()
            || Account::query()->where('money_kind', Account::CASH)->whereNotNull('held_by')->exists();
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
