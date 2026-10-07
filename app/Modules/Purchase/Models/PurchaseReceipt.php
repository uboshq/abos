<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
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
 * মাল বুঝে নেওয়া — কী সত্যিই এসেছে।
 *
 * এই ডকুমেন্টটাই স্টক বাড়ায়, আর এটাই দায় জন্মায়। দুইটা একই ট্রানজেকশনে
 * বসে (প্ল্যান WP-0.3): ইভেন্টে করলে একটা বসে অন্যটা ব্যর্থ হতে পারত, আর
 * তখন গুদামে মাল থাকত অথচ খাতায় থাকত না — কোনো ভুল বার্তা ছাড়াই।
 *
 * দায়টা এখনো সরবরাহকারীর নামে নয়, ২১৬০ "প্রাপ্ত মাল, বিল আসেনি"-তে বসে।
 * বিল এলে সেখান থেকে সরে সরবরাহকারীর নামে যায়। কারণটা ধ্রুবকের মন্তব্যে।
 */
class PurchaseReceipt extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'pur_receipts';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'supplier_id', 'warehouse_id', 'purchase_order_id',
        'trx_date', 'supplier_challan_no', 'total',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'cancelled_at' => 'datetime',
            'total' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
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
                ->orWhere('supplier_challan_no', 'like', "%{$term}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->search($term));
        });
    }

    /** স্টকের চলাচলে যে উৎস বসে — StockService::move()-এ পাঠানো হয়। */
    public const STOCK_SOURCE = 'purchase_receipt';

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'purchase_receipt';
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
        return ['purchase.receipt.show', ['receipt' => $this->id]];
    }
}
