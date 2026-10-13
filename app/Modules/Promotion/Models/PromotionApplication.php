<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Promotion\Support\BenefitKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * কোন বিলে কোন অফার বসেছিল, আর কত সুবিধা।
 *
 * ── ⭐ এটাই §১৮-এর ভিত্তি ────────────────────────────────────────────
 * *"পুরনো বিলে বসানো অফার অপরিবর্তিত থাকবে"*।
 *
 * ── ⚠️ সংখ্যাটা এখানে **জমে যায়**, আর কারণটা মালিকের সিদ্ধান্ত ───────
 * ⓘ ২৬ সেপ্টেম্বর: *"barate hole barabe, komate hole komabe"* — মেয়াদ
 * দুই দিকেই বদলানো যায়। ⛔ তাই তারিখ দিয়ে পুরনো বিল বাঁচানো যায় না।
 *
 * ⭐ বাঁচে এভাবে: ছাড়ের অঙ্কটা আর অফারের কাগজ থেকে পড়া হয় না, এই সারি
 * থেকে পড়া হয়। ⚠️ মেয়াদ পিছিয়ে আনলে নতুন বিলে অফারটা আর খাটে না,
 * কিন্তু কাটা বিলের ছাড় যেমন ছিল তেমনই থাকে।
 */
class PromotionApplication extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'promotion_id', 'branch_id', 'source_type', 'source_id', 'source_line_id',
        'customer_id', 'product_id', 'promotion_benefit_id',
        'benefit_kind', 'benefit_amount', 'worth',
        'was_overridden', 'override_reason', 'applied_by',
        'original_worth', 'overridden_by', 'overridden_at',
        'reversed_at', 'reversed_by',
    ];

    protected function casts(): array
    {
        return [
            'benefit_kind' => BenefitKind::class,
            'benefit_amount' => 'decimal:4',
            'worth' => 'decimal:4',
            'original_worth' => 'decimal:4',
            'was_overridden' => 'boolean',
            'overridden_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** ⓘ কোন ধাপের সুবিধা — উপহারের পণ্যটা এখান থেকেই আসে, ফর্ম থেকে নয় */
    public function benefit(): BelongsTo
    {
        return $this->belongsTo(PromotionBenefit::class, 'promotion_benefit_id');
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(PromotionGiftIssue::class);
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
