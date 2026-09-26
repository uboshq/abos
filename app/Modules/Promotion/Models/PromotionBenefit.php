<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Support\BenefitKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ক্রেতা কী পান — ছাড়, উপহার, জমা বা পয়েন্ট।
 *
 * ⚠️ উপহারের পণ্যটা **আসল পণ্য** (§৮), তাই সত্যিকারের বিদেশি চাবি।
 * ⛔ কাল্পনিক আইটেম হলে মজুদ কমত না, আর *"কত উপহার বেরিয়েছে"* প্রশ্নের
 * কোনো উত্তর থাকত না।
 */
class PromotionBenefit extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'promotion_id', 'promotion_condition_id', 'kind',
        'amount', 'gift_product_id', 'gift_unit_id', 'cap_per_bill',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BenefitKind::class,
            'amount' => 'decimal:4',
            'cap_per_bill' => 'decimal:4',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(PromotionCondition::class, 'promotion_condition_id');
    }

    public function giftProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }

    /**
     * ⛔ এই সুবিধাটা সত্যিই দেওয়া যায় কি না।
     *
     * ── ⚠️ কেন এই প্রশ্নটা আলাদা করে লাগে ───────────────────────────
     * ⓘ একটা `goods` সুবিধার সারিতে পণ্যটা `null` হতে পারে — কেউ
     * অফারটা বানাতে গিয়ে পণ্য বাছতে ভুলে গেছেন, বা পণ্যটা পরে মুছে গেছে।
     *
     * ⛔ তখন অফারটা তালিকায় *"চলছে"* বলত, বিলে খাটত, আর উপহারের জায়গায়
     * **কিছুই দিত না** — কোথাও কোনো ভুলবার্তা ছাড়াই। ⚠️ ক্রেতা তাঁর
     * প্রাপ্য মালটা পেতেন না, আর কেউ জানতেও পারত না।
     *
     * ⓘ ইঞ্জিন তাই এমন সারি **বাদ** দেয়, আর তৈরির পর্দা ওটা বসাতেই দেয় না।
     */
    public function isDeliverable(): bool
    {
        if ($this->kind->movesStock()) {
            return $this->gift_product_id !== null
                && bccomp((string) $this->amount, '0', 4) > 0;
        }

        return bccomp((string) $this->amount, '0', 4) > 0;
    }
}
