<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * একটা বীমা পলিসি — কোন কোম্পানি, কী বীমা করা, কত টাকার, কবে পর্যন্ত।
 *
 * ⓘ মেয়াদটা (`starts_on` → `ends_on`) সবসময় **চলতি** মেয়াদ; নবায়ন হলে
 * সামনে সরে। আগের মেয়াদগুলো থাকে প্রিমিয়ামের সারিতে ([[InsurancePremium]]),
 * তাই ইতিহাস হারায় না।
 */
class InsurancePolicy extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const VEHICLE = 'vehicle';

    public const WAREHOUSE = 'warehouse';

    public const GOODS = 'goods';

    public const PEOPLE = 'people';

    public const OTHER = 'other';

    /** @var list<string> */
    public const COVERS = [self::VEHICLE, self::WAREHOUSE, self::GOODS, self::PEOPLE, self::OTHER];

    /**
     * নবায়নের কত দিন আগে থেকে সতর্ক করা হয় — মালিকের সংখ্যা।
     *
     * ⓘ এজেন্টের কাগজ আসতে আর টাকা ছাড়তে সাধারণত দুই-তিন সপ্তাহ লাগে;
     * শেষ সপ্তাহে জানলে মাঝের কয়েক দিন মাল বীমা ছাড়াই থাকত।
     */
    public const WARN_DAYS = 30;

    protected $table = 'fin_insurance_policies';

    protected $fillable = [
        'company_id', 'branch_id', 'institution_id', 'policy_no', 'covers', 'subject',
        'sum_insured', 'premium', 'starts_on', 'ends_on', 'notes', 'is_active', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sum_insured' => 'decimal:2',
            'premium' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function premiums(): HasMany
    {
        return $this->hasMany(InsurancePremium::class, 'policy_id')->orderByDesc('period_from');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * নবায়নের সময় ঘনিয়েছে — আজ থেকে [[WARN_DAYS]] দিনের মধ্যে শেষ, অথবা
     * শেষ হয়ে গেছে অথচ পলিসিটা এখনো চালু।
     *
     * ⚠️ মেয়াদ-পেরোনোগুলো ইচ্ছাকৃতভাবে ভেতরে: ওগুলোই সবচেয়ে জরুরি, আর
     * "৩০ দিনের মধ্যে" বলে বাদ দিলে সতর্কবার্তা ঠিক তখনই চুপ করত যখন
     * দেরি হয়ে গেছে।
     */
    public function scopeDueForRenewal(Builder $query, ?CarbonInterface $today = null): Builder
    {
        $today ??= now();

        return $query->active()->whereDate('ends_on', '<=', $today->copy()->addDays(self::WARN_DAYS)->toDateString());
    }

    public function daysLeft(?CarbonInterface $today = null): int
    {
        $today ??= now();

        return (int) $today->copy()->startOfDay()->diffInDays($this->ends_on->copy()->startOfDay(), false);
    }

    public function hasLapsed(?CarbonInterface $today = null): bool
    {
        return $this->daysLeft($today) < 0;
    }

    public function isDueForRenewal(?CarbonInterface $today = null): bool
    {
        return $this->is_active && $this->daysLeft($today) <= self::WARN_DAYS;
    }
}
