<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Engines\Drill\DrillResolver;
use App\Models\Branch;
use App\Models\User;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * মজুদের একটা চলাচল — খতিয়ানের একটা সারি।
 *
 * সারিগুলো কখনো বদলায় না, কখনো মোছে না। ভুল হলে উল্টো সারি বসে, ঠিক
 * যেমন হিসাবের খাতায়। মুছে ফেললে "গতকাল স্টক কত ছিল" প্রশ্নের উত্তর
 * থাকত না, আর গণনার পার্থক্য কোথা থেকে এল তা কেউ বলতে পারত না।
 *
 * তিনটা কলাম কেন — module.php-র ব্যাখ্যা দেখুন। সংক্ষেপে: একটা সারি
 * প্রায়ই একসাথে দুইটা অবস্থা বদলায়, আর দুই সারিতে ভাগ করলে একটা বসে
 * অন্যটা না বসার সুযোগ তৈরি হয়।
 */
class StockMovement extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $table = 'inv_stock_movements';

    protected $fillable = [
        'company_id', 'branch_id', 'product_id', 'warehouse_id', 'trx_date',
        'floor_change', 'reserved_change', 'hold_change', 'reason_code_id',
        'source_type', 'source_id', 'document_no', 'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'floor_change' => 'decimal:4',
            'reserved_change' => 'decimal:4',
            'hold_change' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class, 'reason_code_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function scopeInWarehouse(Builder $query, ?int $warehouseId): Builder
    {
        return $query->when($warehouseId, fn (Builder $q, int $id) => $q->where('warehouse_id', $id));
    }

    /** এই সারিটা কোন ডকুমেন্ট থেকে এল — নিয়ম ১। */
    public function drill(): array
    {
        return app(DrillResolver::class)
            ->describe($this->source_type, $this->source_id);
    }
}
