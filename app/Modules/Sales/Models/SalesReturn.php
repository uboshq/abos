<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * বিক্রয় ফেরত — মাল ফিরে এসেছে।
 *
 * বিলটা বাতিল করা আর ফেরত নেওয়া এক নয়: বাতিল মানে বিক্রিটাই হয়নি,
 * ফেরত মানে হয়েছিল আর তার একটা অংশ ফিরে এসেছে। খাতায় দুইটা আলাদা
 * ঘটনা, আর ইতিহাসেও আলাদা থাকা উচিত।
 */
class SalesReturn extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    /** স্টকের সারিতে এই নামেই বসে। */
    public const STOCK_SOURCE = 'sales_return';

    protected $table = 'sal_returns';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'customer_id', 'warehouse_id', 'sales_invoice_id', 'reason_code_id',
        'trx_date', 'subtotal', 'tax', 'total', 'cost_of_goods',
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
            'cost_of_goods' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class)->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
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
                ->orWhereHas('customer', fn (Builder $c) => $c->search($term));
        });
    }

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'sales_return';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->customer?->name() ?? $this->document_no;
    }

    public function drillRoute(): array
    {
        return ['sales.return.show', ['return' => $this->id]];
    }
}
