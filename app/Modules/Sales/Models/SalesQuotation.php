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
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * বিক্রয় উদ্ধৃতি — ডিলারকে বলা দর, মেয়াদসহ (NEXUS §৮)।
 *
 * ⓘ খাতায় কিছু বসে না, মজুদেও কিছু ধরা পড়ে না — উদ্ধৃতি একটা প্রস্তাব।
 * ⭐ রাজি হলে এক চাপে একই সারি ও দরে বিক্রয় আদেশ হয়, আর আদেশ জানে সে
 * কোথা থেকে এসেছে (`sal_orders.sales_quotation_id`)।
 *
 * ── ⚠️ মেয়াদ পড়ার সময় গোনা হয়, সারিতে লেখা হয় না ────────────────────────
 * ক্রয়ের উদ্ধৃতিও এভাবেই করে ([[Quotation::isStillGood()]])। ⛔ রাতের
 * কাজে "মেয়াদোত্তীর্ণ" লিখলে কাজটা একদিন না চললে সারিটা বাসি থাকত, আর
 * মেয়াদ পেরোনো দরে আদেশ হয়ে যেত। তারিখ থেকে গুনলে উত্তরটা কখনো বাসি হয় না।
 */
class SalesQuotation extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'sal_quotations';

    public const DRAFT = DocumentStatus::DRAFT;

    /** সইয়ের অপেক্ষায় — অনুমোদনের ছক বসানো থাকলে */
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    /** ডিলারের হাতে গেছে */
    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /** ⓘ কখনো সারিতে বসে না — [[effectiveStatus()]] তারিখ থেকে গোনে */
    public const EXPIRED = 'expired';

    public const CANCELLED = DocumentStatus::CANCELLED;

    public const CONVERTED = 'converted';

    /**
     * যে অবস্থাগুলোয় মেয়াদ খাটে — এখনো কোনো উত্তরে পৌঁছায়নি।
     *
     * ⚠️ একটাই তালিকা, কারণ মডেলের [[isExpired()]] আর তালিকার
     * [[scopeExpired()]] দুইটাই এটা পড়ে; দুই জায়গায় হাতে লিখলে একদিন
     * পর্দা "মেয়াদোত্তীর্ণ" বলত আর ছাঁকনি বলত না।
     *
     * @var list<string>
     */
    public const EXPIRABLE = [self::DRAFT, self::SUBMITTED, self::APPROVED, self::SENT, self::ACCEPTED];

    /** @var list<string> পর্দার ছাঁকনির ক্রম */
    public const STATES = [
        self::DRAFT, self::SUBMITTED, self::APPROVED, self::SENT, self::ACCEPTED,
        self::REJECTED, self::EXPIRED, self::CANCELLED, self::CONVERTED,
    ];

    /** অনুমোদনের ছকে এই কাজের নাম — module.php-র `approvals`-এ ঘোষিত */
    public const APPROVAL_ACTION = 'quotation';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'customer_id', 'trx_date', 'valid_until',
        'price_list_id', 'payment_term_id', 'delivery_terms',
        'subtotal', 'discount', 'header_discount', 'tax', 'total',
        'status', 'narration',
        'submitted_at', 'approved_at', 'sent_at', 'answered_at', 'answer_note',
        'sales_order_id', 'converted_at', 'converted_by',
        'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'valid_until' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'answered_at' => 'datetime',
            'converted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'header_discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesQuotationLine::class)->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * মেয়াদ পেরিয়েছে কি না — আজকের তারিখ ধরে।
     *
     * ⓘ `valid_until` দিনটা নিজে বৈধ: "৩০ তারিখ পর্যন্ত" মানে ৩০ তারিখেও চলবে।
     */
    public function isExpired(?Carbon $on = null): bool
    {
        if (! in_array($this->status, self::EXPIRABLE, true) || $this->valid_until === null) {
            return false;
        }

        return $this->valid_until->lt(($on ?? Carbon::today())->copy()->startOfDay());
    }

    /** পর্দায় যে অবস্থা দেখানো হয় — মেয়াদ পেরোলে "মেয়াদোত্তীর্ণ"। */
    public function effectiveStatus(): string
    {
        return $this->isExpired() ? self::EXPIRED : (string) $this->status;
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereIn('status', self::EXPIRABLE)
            ->whereDate('valid_until', '<', Carbon::today()->toDateString());
    }

    /** পর্দার ছাঁকনি — "মেয়াদোত্তীর্ণ" সারিতে নেই, তাই আলাদা পথ। */
    public function scopeInState(Builder $query, ?string $state): Builder
    {
        if ($state === null || $state === '' || ! in_array($state, self::STATES, true)) {
            return $query;
        }

        if ($state === self::EXPIRED) {
            return $query->expired();
        }

        $query->where('status', $state);

        // ⚠️ মেয়াদ পেরোনোগুলো নিজের ঘরে দেখায় — "পাঠানো" ঘরে আবার নয়
        if (in_array($state, self::EXPIRABLE, true)) {
            $query->whereDate('valid_until', '>=', Carbon::today()->toDateString());
        }

        return $query;
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
        return 'sales_quotation';
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
        return ['sales.quotation.show', ['quotation' => $this->id]];
    }
}
