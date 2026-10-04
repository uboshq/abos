<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * বিক্রয় আদেশ — গ্রাহক কী চেয়েছেন।
 *
 * নিশ্চিত হলে মালটা অর্ডারে ধরা পড়ে (Reserved): তাকেই থাকে, শুধু আর বেচা
 * যায় না। না ধরলে একই শেষ কার্টনটা দুইজনকে বেচা হত, আর ভুলটা ধরা পড়ত মাল
 * দিতে গিয়ে — ক্রেতার সামনে।
 */
class SalesOrder extends Model implements Drillable
{
    use BelongsToCompany;
    use \App\Modules\Sales\Models\Concerns\CarriesTheSalesChannel;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'sal_orders';

    /** স্টকের চলাচলে যে উৎস বসে। */
    public const STOCK_SOURCE = 'sales_order';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'customer_id', 'source', 'warehouse_id', 'trx_date', 'deliver_on',
        'subtotal', 'discount', 'tax', 'total',
        'status', 'narration', 'created_by', 'created_by_customer_id',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
        // ⭐ DO বিক্রয় আদেশে মেশানো, ধাপ ১ — নকশার §১.৩ (মালিক, ৪ অক্টোবর ২০২৬; [[SalesOrderStatus]])
        'submitted_at', 'approved_at',
        'credit_short', 'credit_held_at', 'credit_checked_at', 'credit_warnings',
        'depot_check_at', 'depot_check_by',
        'delivery_status', 'billing_status',
        'closed_at', 'closed_by', 'close_reason',
        'hold_mode',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'deliver_on' => 'date',
            'cancelled_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'credit_short' => 'decimal:4',
            'credit_held_at' => 'datetime',
            'credit_checked_at' => 'datetime',
            'credit_warnings' => 'array',
            'depot_check_at' => 'datetime',
            'closed_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** ⓘ যে উদ্ধৃতি থেকে এই আদেশ এসেছে — না এলে নাল ([[SalesQuotationService::convert()]]) */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function challans(): HasMany
    {
        return $this->hasMany(DeliveryChallan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ⓘ কে আদেশটা বন্ধ করলেন — পাতায় নাম ([[SalesOrderService::close()]]) */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * ⓘ ধারার ছাপ আর অগ্রগতি কাগজের লেখা নয় — সইয়ের ছাপে ([[DocumentFingerprint]]) গোনা হয় না।
     *
     * ⚠️ গুনলে জমা, সীমার যাচাই বা একটা চালানই কাগজ "বদলেছে" বলত, আর সই পাওয়া আদেশ আবার সই চাইত।
     *
     * @return list<string>
     */
    public function fingerprintIgnores(): array
    {
        return [
            'submitted_at', 'approved_at',
            'credit_short', 'credit_held_at', 'credit_checked_at', 'credit_warnings',
            'depot_check_at', 'depot_check_by',
            'delivery_status', 'billing_status',
            'closed_at', 'closed_by', 'close_reason',
            'hold_mode',
        ];
    }

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
                ->orWhereHas('customer', fn (Builder $c) => $c->search($term));
        });
    }

    public static function drillSourceType(): string
    {
        return 'sales_order';
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
        return ['sales.order.show', ['order' => $this->id]];
    }
}
