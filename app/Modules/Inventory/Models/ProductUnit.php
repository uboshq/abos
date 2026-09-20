<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা পণ্যের একটা প্যাক — "এই পণ্যের ১ কার্টনে ২৪ পিস"।
 *
 * ⓘ কেন পণ্যে, এককের মাস্টারে নয়: কার্টনের মাপ পণ্যে পণ্যে আলাদা।
 * কারণ বিস্তারিত মাইগ্রেশনে —
 * `2026_11_20_100000_a_carton_is_not_the_same_size_for_every_product`।
 *
 * ⭐ `factor` সবসময় পণ্যের base-এর হিসাবে (`inv_products.unit_id`):
 * base-এর নিজের সারিতে ১, কার্টনে ২৪। শিকল নেই — কার্টন → বক্স → পিস
 * নয়, সোজা কার্টন = ২৪ পিস। ⓘ এককের মাস্টারের গভীর শিকল
 * (`Unit::toBase()`) এখানে লাগে না, কারণ একটা পণ্যের প্যাক হাতে গোনা।
 */
class ProductUnit extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'inv_product_units';

    protected $fillable = [
        'company_id', 'product_id', 'unit_id', 'factor', 'per_qty', 'per_unit_id',
        'is_purchase_default', 'is_sales_default', 'is_pos_default', 'is_counter_default',
        'barcode', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:6',
            'per_qty' => 'decimal:6',
            'is_purchase_default' => 'boolean',
            'is_sales_default' => 'boolean',
            'is_pos_default' => 'boolean',
            'is_counter_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** "১ কার্টন = ১২ **বক্স**" — কিসের হিসাবে লেখা হয়েছিল। */
    public function perUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'per_unit_id');
    }

    /** এটা কি পণ্যের base — যে এককে মজুদ গোনা হয়। */
    public function isBase(): bool
    {
        return $this->product !== null && $this->unit_id === $this->product->unit_id;
    }
}
