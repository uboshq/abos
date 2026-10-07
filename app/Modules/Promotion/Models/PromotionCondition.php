<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Promotion\Support\ConditionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * কত কিনলে সুবিধাটা খোলে — স্ল্যাবের একটা ধাপ।
 *
 * ⓘ *"৫০ থেকে ৯৯ কার্টন"* একটা সারি, *"১০০ থেকে ১৯৯"* আরেকটা।
 */
class PromotionCondition extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'promotion_id', 'kind', 'value_from', 'value_to',
        'target_id', 'from_time', 'to_time', 'weekdays', 'step_order',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ConditionKind::class,
            'value_from' => 'decimal:4',
            'value_to' => 'decimal:4',
            'target_id' => 'integer',
            'step_order' => 'integer',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(PromotionBenefit::class);
    }

    /**
     * ⭐ এই সংখ্যাটা এই ধাপের ভিতরে পড়ে কি না।
     *
     * ── ⚠️ সীমানা দুইটাই **অন্তর্ভুক্ত**, আর এটা লিখে রাখা দরকার ─────
     * ⓘ স্পেকের টেবিল বলে *"৫০–৯৯ → ৫%"*, তারপর *"১০০–১৯৯ → ৮%"*।
     * ⛔ `from` বা `to` যেকোনো একটাকে বাদ দিলে ঠিক ৫০ বা ঠিক ১০০
     * কিনলে ক্রেতা **কোনো ধাপেই** পড়তেন না, বা **দুইটাতেই** পড়তেন।
     *
     * ⚠️ আর ঐ ভুলটা নীরব: ৫১ থেকে ৯৮ পর্যন্ত সব ঠিক চলত, কেবল ঠিক
     * সীমানার সংখ্যাটায় ভুল হত — আর কেউ ঐ সংখ্যাটা দিয়ে পরীক্ষা করত না।
     */
    public function covers(string $amount): bool
    {
        if ($this->value_from !== null && bccomp($amount, (string) $this->value_from, 4) < 0) {
            return false;
        }

        if ($this->value_to !== null && bccomp($amount, (string) $this->value_to, 4) > 0) {
            return false;
        }

        return true;
    }
}
