<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * স্থানান্তরের একটা লাইন — কোন পণ্য, কতটা।
 *
 * দর নেই, ইচ্ছাকৃতভাবে: গুদাম বদলালে মালের মূল্য বদলায় না, আর এখানে
 * একটা দর বসালে কেউ ভাবত সেটা দিয়ে কিছু একটা হিসাব হচ্ছে।
 */
class StockTransferLine extends Model
{
    use BelongsToCompany;
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'inv_transfer_lines';

    protected $fillable = [
        'company_id', 'stock_transfer_id', 'product_id',
        'qty', 'entered_qty', 'entered_unit_id', 'line_no',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'entered_qty' => 'decimal:4'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
