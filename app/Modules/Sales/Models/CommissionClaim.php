<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ডিলারকে দেওয়া কমিশন, যা কোম্পানির কাছে দাবি।
 *
 * টাকাটা ডিপো আগে দেয় — বিল থেকে কেটে, বা নগদে — আর মাস শেষে
 * কোম্পানির লেজারে সমন্বয় হয়। তাই এটা ছাড় নয়, একটা **সম্পদ**:
 * Dr কমিশনের দাবি (১১৫০) · Cr ডিলার।
 *
 * ── কেন এটা নিজের কাগজ, বিলের একটা ঘর নয় ────────────────────────────
 * একই ডিলারের একই মাসে কয়েকটা বিলে কমিশন বসতে পারে, আবার বিল ছাড়াও
 * (নগদে)। বিলের ভেতরে একটা ঘর রাখলে বিল-ছাড়া কমিশনটার কোনো জায়গাই
 * থাকত না, আর "কোম্পানির কাছে মোট কত দাবি" প্রশ্নের উত্তর দিতে হলে
 * প্রতিটা বিল খুলে দেখতে হত।
 */
class CommissionClaim extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** দেওয়া হয়েছে, কোম্পানি এখনো কিছু বলেনি। */
    public const PENDING = 'pending';

    /** কোম্পানি মেনেছে — তাদের হিসাবে সমন্বয় হয়ে গেছে। */
    public const SETTLED = 'settled';

    /** কোম্পানি মানেনি — দাবিটা এখন ডিপোর নিজের খরচ। */
    public const REJECTED = 'rejected';

    protected $table = 'sal_commission_claims';

    /** খতিয়ানে এই কাগজের নাম। */
    public const STOCK_SOURCE = 'commission_claim';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'trx_date',
        'customer_id', 'supplier_id', 'sales_invoice_id',
        'base_amount', 'rate_percent', 'rate_amount', 'amount',
        'status', 'narration', 'decision_reason', 'decided_on', 'decided_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'decided_on' => 'date',
            'base_amount' => 'decimal:4',
            'rate_percent' => 'decimal:4',
            'rate_amount' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /**
     * শতাংশটা কিসের উপর — সবসময় ভিত্তির উপর, বিক্রয়ের উপর নয়।
     *
     * কোম্পানি "৫%" বলতে বিলের অঙ্কের উপর ৫% বোঝায়, আর ওই অঙ্কটাই
     * `base_amount`-এ জমা থাকে। ভিত্তিটা না রাখলে ছয় মাস পরে কেউ
     * বলতে পারত না ২,৫০০ টাকা কীভাবে এসেছিল।
     */
    public function describeRate(): string
    {
        if ($this->rate_percent !== null && bccomp((string) $this->rate_percent, '0', 4) > 0) {
            return rtrim(rtrim((string) $this->rate_percent, '0'), '.').'%';
        }

        return (string) ($this->rate_amount ?? $this->amount);
    }

    public static function drillSourceType(): string
    {
        return self::STOCK_SOURCE;
    }

    public function drillDocumentNo(): string
    {
        return (string) ($this->document_no ?? $this->public_id);
    }

    public function drillLabel(): string
    {
        return trim(($this->document_no ?? '').' — '.($this->customer?->name() ?? ''), ' —');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['sales.commission.index', []];
    }
}
