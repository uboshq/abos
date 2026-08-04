<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ক্রয় আদেশ — কী আনতে বলেছি।
 *
 * এই ডকুমেন্টটা কিছুই নাড়ায় না: স্টকেও নয়, খাতাতেও নয়। আদেশ দেওয়া মানে
 * শুধু একটা অভিপ্রায়, আর অভিপ্রায়ের কোনো হিসাব হয় না। মাল আসার আগেই দায়
 * বসালে সরবরাহকারী মাল না পাঠালে ওই দায়টা কেউ সরাত না।
 *
 * তাই আদেশের একমাত্র কাজ পরে গোনা: "কত আনতে বলেছি, আর কত এসেছে"।
 */
class PurchaseOrder extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'pur_orders';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'supplier_id', 'warehouse_id', 'trx_date', 'expected_on',
        'subtotal', 'discount', 'tax', 'total',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'expected_on' => 'date',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এখনো যেসব আদেশের মাল পুরো আসেনি।
     *
     * বাতিল ও খসড়া বাদ: বাতিলের মাল আর আসবে না, আর খসড়া এখনো কাউকে
     * পাঠানোই হয়নি।
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::CONFIRMED);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('document_no', 'like', "%{$term}%")
                ->orWhere('narration', 'like', "%{$term}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->search($term));
        });
    }

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'purchase_order';
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
        return ['purchase.order.show', ['order' => $this->id]];
    }
}
