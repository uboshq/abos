<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কম্বো বা বান্ডলের একটা উপাদান — *"এই পণ্যটা অন্তত এতটা"*।
 *
 * ⭐ একটা কম্বোর **সবগুলো** উপাদান একসাথে বিলে থাকলে তবেই অফারটা খোলে
 * ([[BillPromotionEngine]])। ⚠️ [[PromotionCondition]]-এর সারিগুলো উল্টো —
 * ওগুলো বিকল্প, একটা মিললেই হয়; তাই দুইটা আলাদা টেবিল।
 */
class ComboItem extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'promotion_combo_items';

    protected $fillable = ['promotion_id', 'product_id', 'min_qty'];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'min_qty' => 'decimal:4',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
