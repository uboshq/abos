<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * দর তালিকার একটা সারি — পণ্য (+ ঐচ্ছিক একক) → দর, মেয়াদসহ (মালিক, ৫ অক্টোবর ২০২৬)।
 *
 * ⓘ অডিট-খাতায় প্রতিটা দর-বদল বসে ([[IsAudited]]), তাই ইতিহাসের আলাদা টেবিল নেই — [[SalePriceBook::history()]]-এর
 * একই কারণে। ⚠️ মেয়াদ বদলালে নতুন সারি (নতুন `valid_from`), পুরনোটা থাকে; দর কোনটা খাটবে তা [[SalesPrice]] বলে।
 */
final class PriceListItem extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'sal_price_list_items';

    protected $fillable = [
        'company_id', 'price_list_id', 'product_id', 'unit_id', 'price', 'valid_from', 'valid_to', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
