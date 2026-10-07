<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা ডেলিভারি অর্ডারের একটা সারির জন্য আটকানো মাল — [[DeliveryOrderStock]] লেখে, অন্য কেউ নয়।
 *
 * ⓘ `firm` মানে মজুদের খাতায় `reserved` বসানো — অন্য কেউ বেচতে পারে না। `soft` মানে কেবল দেখানো — বিক্রি চলে।
 */
final class DeliveryOrderStockHold extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const FIRM = 'firm';

    public const SOFT = 'soft';

    /** মজুদের খাতায় উৎসের নাম — [[StockService::move()]]-এর `sourceType` */
    public const STOCK_SOURCE = 'delivery_order';

    protected $table = 'sal_do_stock_holds';

    protected $fillable = [
        'company_id', 'branch_id', 'delivery_order_id', 'delivery_order_line_id', 'product_id', 'warehouse_id',
        'wanted_qty', 'qty', 'kind', 'held_at', 'firm_until', 'expires_at', 'released_at', 'release_reason', 'consumed_qty',
    ];

    protected function casts(): array
    {
        return [
            'wanted_qty' => 'decimal:4',
            'qty' => 'decimal:4',
            'consumed_qty' => 'decimal:4',
            'held_at' => 'datetime',
            'firm_until' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /** @return BelongsTo<DeliveryOrder, $this> */
    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<DeliveryOrderLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderLine::class, 'delivery_order_line_id');
    }
}
