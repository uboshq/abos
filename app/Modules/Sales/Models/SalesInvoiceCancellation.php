<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * বাতিল-ইনভয়েস (Cancellation Invoice) — মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান।
 *
 * ⓘ ভুল পাকা ইনভয়েসের পুরো উল্টো কাগজ, নিজের নম্বরে (CXL-xxxx, নম্বরের পর্দা থেকে বদলানো যায়)। আসল ইনভয়েস থাকে —
 * "বাতিল" অবস্থায়, পাশে এই কাগজের সূত্র। পাকা হওয়ার মুহূর্তে খাতা আর মজুদ উল্টায়, উল্টো সারি এই নম্বরে
 * ([[SalesInvoiceCancellationService::confirm()]])।
 *
 * ⛔ গেট পাস হয়ে মাল বেরিয়ে গেলে এই কাগজ নয় — তখন ফেরত (SRT)। মাস বন্ধ থাকলেও নয়।
 */
class SalesInvoiceCancellation extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    // ⭐ বিক্রয়কর্মী কেবল নিজের বাঁধা ডিলারের কাগজ দেখেন — ⛔১৬, ২ অক্টোবর ২০২৬ ([[DealerScope]])
    use \App\Core\Concerns\ScopedToUserDealers;
    use ScopedToUserBranch;
    use SoftDeletes;

    /** সইয়ের অপেক্ষায় — মালিকের সই না হওয়া পর্যন্ত খাতা ছোঁয় না */
    public const AWAITING = 'awaiting';

    /** খতিয়ান আর মজুদে এই নামে — উল্টো সারিগুলো অবশ্য বিল আর চালানের নিজের `:reversal`/`:cancel` নামে বসে */
    public const SOURCE = 'sales_invoice_cancellation';

    protected $table = 'sal_invoice_cancellations';

    protected $fillable = [
        'company_id', 'branch_id', 'sales_invoice_id', 'customer_id',
        'document_no', 'trx_date', 'reason',
        'subtotal', 'tax', 'total',
        'status', 'created_by', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'confirmed_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isAwaiting(): bool
    {
        return $this->status === self::AWAITING;
    }

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return self::SOURCE;
    }

    public function drillDocumentNo(): string
    {
        return (string) $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->customer?->name() ?? (string) $this->document_no;
    }

    public function drillRoute(): array
    {
        return ['sales.cancellation.show', ['cancellation' => $this->id]];
    }
}
