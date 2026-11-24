<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা পণ্যের এক মাসের বিক্রির লক্ষ্য (পরিমাণ আর টাকা) — [[ProductTargetService]] লেখে আর পড়ে।
 *
 * ⓘ অর্জন এখানে থাকে না; প্রতিবার বিলের লাইন থেকে গোনা হয়।
 */
final class ProductTarget extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_product_targets';

    protected $fillable = ['company_id', 'product_id', 'month', 'qty', 'amount', 'created_by'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'qty' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
