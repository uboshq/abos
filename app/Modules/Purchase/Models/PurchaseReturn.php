<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ক্রয় ফেরত — মাল সরবরাহকারীর কাছে ফিরে যাচ্ছে।
 */
class PurchaseReturn extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    public const STOCK_SOURCE = 'purchase_return';

    protected $table = 'pur_returns';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'supplier_id', 'warehouse_id', 'purchase_bill_id', 'reason_code_id',
        'trx_date', 'subtotal', 'tax', 'total',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class, 'reason_code_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('document_no', 'like', "%{$term}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->search($term));
        });
    }

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'purchase_return';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->supplier?->name() ?? $this->document_no;
    }

    public function drillRoute(): array
    {
        return ['purchase.return.show', ['return' => $this->id]];
    }
}
