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
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    // ⭐ বিজ্ঞপ্তিতে "নাম · পয়েন্ট" ([[NamesItsCustomerInNotices]])
    use \App\Modules\Sales\Models\Concerns\NamesItsCustomerInNotices;
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    // ⭐ বিক্রয়কর্মী কেবল নিজের বাঁধা ডিলারের কাগজ দেখেন — ⛔১৬, ২ অক্টোবর ২০২৬ ([[DealerScope]])
    use \App\Core\Concerns\ScopedToUserDealers;
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
     * ⭐ নতুন সংস্করণে বদলেছে — পুরনো সংস্করণ, কেবল পড়ার জন্য (মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক নিয়ম)।
     *
     * ⓘ কোনো ধাপের তালিকায় নেই ([[EXPIRABLE]] নয়, বাতিলযোগ্য নয়), তাই সেবার প্রতিটা দরজা নিজে থেকেই বন্ধ;
     * সম্পাদনা কেবল খসড়ায় ([[SalesQuotationPolicy::update()]])। তালিকার ট্যাবেও নেই — পুরনোগুলো দেখা যায়
     * নতুনটার ইতিহাসে আর "সংস্করণ" পর্দায়।
     */
    public const REVISED = 'revised';

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
        self::REJECTED, self::EXPIRED, self::CANCELLED, self::CONVERTED, self::REVISED,
    ];

    /**
     * ⭐ তালিকার ট্যাব — খসড়া, পাঠানো, গৃহীত, মেয়াদোত্তীর্ণ, আদেশ হয়েছে, হারানো (মালিকের আন্তর্জাতিক
     * পরিকল্পনা, ৪ অক্টোবর ২০২৬)। ⓘ `null` মানে "সব" (পুরনো সংস্করণ বাদে); `expired` তারিখ থেকে গোনা।
     *
     * ⓘ "খসড়া" ট্যাবে সইয়ের অপেক্ষার আর অনুমোদিত কাগজও — তিনটাই এখনো ডিলারের হাতে যায়নি; সারির ব্যাজ
     * আসল ধাপটা বলে। ⓘ "হারানো" = ডিলার রাজি নন, বা বাতিল।
     *
     * @var array<string, list<string>|null>
     */
    public const TABS = [
        'all' => null,
        'draft' => [self::DRAFT, self::SUBMITTED, self::APPROVED],
        'sent' => [self::SENT],
        'accepted' => [self::ACCEPTED],
        'expired' => [self::EXPIRED],
        'converted' => [self::CONVERTED],
        'lost' => [self::REJECTED, self::CANCELLED],
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
        'root_quotation_id', 'revision_no', 'revised_from_id', 'superseded_at', 'superseded_by',
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
            'superseded_at' => 'datetime',
            'revision_no' => 'integer',
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

    /** মূল উদ্ধৃতি — মূলটার নিজের ঘর খালি। */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_quotation_id');
    }

    /** ঠিক আগের সংস্করণ। */
    public function revisedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revised_from_id');
    }

    /** যে সংস্করণ এটাকে বদলেছে — পুরনোটার পাতা নতুনটার দিকে আঙুল তোলে। */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'revised_from_id');
    }

    /** মূলের id — মূল নিজে হলে নিজের। */
    public function rootId(): int
    {
        return (int) ($this->root_quotation_id ?? $this->getKey());
    }

    /**
     * একই উদ্ধৃতির সব সংস্করণ — মূলসহ, পুরনো থেকে নতুন।
     *
     * ⓘ মূল খোঁজা হয় id দিয়ে, বাকিগুলো `root_quotation_id` দিয়ে; কোম্পানির দেয়াল মডেলের নিজের স্কোপে।
     *
     * @return Builder<self>
     */
    public function family(): Builder
    {
        $rootId = $this->rootId();

        return self::query()
            ->where(fn (Builder $q) => $q->whereKey($rootId)->orWhere('root_quotation_id', $rootId))
            ->orderBy('revision_no');
    }

    /** পুরনো সংস্করণ — কেবল পড়ার জন্য। */
    public function isSuperseded(): bool
    {
        return $this->status === self::REVISED;
    }

    /**
     * ⓘ ডিলার কাগজটা দেখেননি — জমা বা অনুমোদিত, এখনো পাঠানো হয়নি। তখন বদল মানে "আবার খসড়ায়" (একই নম্বর);
     * পাঠানোর পর থেকে বদল মানে নতুন সংস্করণ ([[SalesQuotationService::revise()]])।
     */
    public function revisesInPlace(): bool
    {
        return in_array($this->status, [self::SUBMITTED, self::APPROVED], true);
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

    /**
     * তালিকার ট্যাব ([[TABS]])। ⚠️ অচেনা নাম মানে "সব" — ভুল ঠিকানায় খালি পাতা নয়।
     *
     * ⓘ "সব"-সহ প্রতিটা ট্যাব পুরনো সংস্করণ বাদ দেয়: একই উদ্ধৃতি তিনবার তালিকায় এলে মনে হত তিনটা আলাদা দর।
     */
    public function scopeInTab(Builder $query, ?string $tab): Builder
    {
        $states = self::TABS[(string) $tab] ?? null;

        if ($states === null) {
            return $query->where('status', '<>', self::REVISED);
        }

        if ($states === [self::EXPIRED]) {
            return $query->expired();
        }

        $query->whereIn('status', $states);

        // ⚠️ মেয়াদ পেরোনোগুলো কেবল নিজের ট্যাবে — "পাঠানো"-তে আবার নয়
        if (array_intersect($states, self::EXPIRABLE) !== []) {
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
