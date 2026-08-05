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
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ক্রয় বিল — কী দিতে হবে।
 *
 * বিলটা দায়টা সরবরাহকারীর নামে বসায়, আর ঠিক সেই কারণে সরবরাহকারীর প্রদেয়
 * নিজে থেকেই মেলে: প্রদেয়ের সংখ্যাটা কোথাও জমা থাকে না, খতিয়ান থেকে গোনা
 * হয়। জমা রাখলে বিল বাতিল হলে দুই জায়গায় বদলাতে হত, আর একটা বাদ পড়ত।
 */
class PurchaseBill extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'pur_bills';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'supplier_id', 'trx_date', 'due_on', 'supplier_bill_no',
        'subtotal', 'discount', 'tax', 'total',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'due_on' => 'date',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
                ->orWhere('supplier_bill_no', 'like', "%{$term}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->search($term));
        });
    }

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'purchase_bill';
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
        return ['purchase.bill.show', ['bill' => $this->id]];
    }
}
