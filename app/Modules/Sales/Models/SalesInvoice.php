<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
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
 * বিক্রয় বিল — টাকা পাওনা হলো।
 *
 * এখানেই আয় ও প্রাপ্য খাতায় বসে, আর একই সাথে বিক্রীত পণ্যের ব্যয়ও —
 * নাহলে লাভ-ক্ষতির হিসাবে আয় থাকত কিন্তু তার পেছনের খরচ থাকত না, আর
 * মুনাফা বাস্তবের চেয়ে বেশি দেখাত।
 */
class SalesInvoice extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'sal_invoices';

    public const STOCK_SOURCE = 'sales_invoice';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',

        /*
         * টিলের পাঠানো চাবি — একই কার্টের দ্বিতীয় অনুরোধ চেনার জন্য।
         *
         * বেশিরভাগ বিলে খালি: সরাসরি বিক্রয়, ইমপোর্ট, চালান থেকে বিল
         * — কেউ চাবি পাঠায় না, আর তাদের কিছু বদলায় না।
         */
        'idempotency_key',

        /*
         * কাউন্টারে অপেক্ষা করছে — ক্রেতা টাকা আনতে গেছেন।
         *
         * তারিখ থাকা মানে ঝুলে আছে, না থাকা মানে সাধারণ খসড়া। নতুন
         * কোনো স্ট্যাটাস নয়, নাহলে "খসড়া কয়টা" দুই জায়গায় জিজ্ঞেস
         * করতে হত।
         */
        'parked_at',

        'customer_id', 'warehouse_id', 'trx_date', 'due_on',
        'subtotal', 'discount', 'tax', 'total', 'cost_of_goods',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'due_on' => 'date',
            'cancelled_at' => 'datetime',
            'parked_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
            'cost_of_goods' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function collectionLines(): HasMany
    {
        return $this->hasMany(CollectionLine::class, 'sales_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এই বিলের বিপরীতে কত আদায় হয়েছে।
     *
     * জমা রাখা হয় না — আদায়ের লাইন থেকে গোনা হয়। জমা রাখলে একটা আদায়
     * বাতিল হলে দুই জায়গায় বদলাতে হত, আর একটা বাদ পড়ত।
     */
    public function collectedAmount(): string
    {
        /*
         * কেবল খাতায় বসা আদায়।
         *
         * আগে এখানে "বাতিল ছাড়া সব" গোনা হত, অর্থাৎ খসড়া আদায়ও। ফলে
         * কেউ একটা আদায় লিখে রেখে দিলেই বিলটা শোধ দেখাত — টাকা হাতে
         * আসার আগেই বিলটা তাগাদার তালিকা থেকে হারিয়ে যেত।
         *
         * ধরা পড়েছে ক্রয়ের আয়না বানাতে গিয়ে, ওখানে একই ভুলটা নকল
         * হওয়ার পর।
         */
        /*
         * তালিকা withCollected() দিয়ে এলে অঙ্কটা সারির সাথেই এসেছে —
         * আদায়ের পর্দায় ২০০টা বকেয়া বিলের জন্য ২০০টা যোগফল নয়, একটা।
         */
        $preloaded = $this->getAttribute('collected_total');

        $collected = $preloaded ?? $this->collectionLines()
            ->whereHas('collection', fn ($q) => $q->whereIn('status', [
                DocumentStatus::CONFIRMED,
                DocumentStatus::CLOSED,
            ]))
            ->sum('amount');

        return (string) ($collected ?: '0');
    }

    /**
     * তালিকার জন্য আদায়ের যোগফল — বিলপ্রতি একটা নয়, পুরোটার জন্য একটা।
     *
     * শর্তগুলো উপরের collectedAmount()-এর হুবহু নকল, আর সেটা ইচ্ছাকৃত:
     * দুই জায়গায় দুই রকম হলে তালিকায় এক অঙ্ক আর একক পাতায় আরেক অঙ্ক
     * দেখা যেত — আর এই মডেলে ঠিক ওই ধরনের ভুল (খসড়া আদায় গোনা) একবার
     * ঘটেছে। একটা বদলালে অন্যটাও বদলাতে হবে।
     */
    public function scopeWithCollected(Builder $query): Builder
    {
        $collected = CollectionLine::query()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('sal_collection_lines.sales_invoice_id', 'sal_invoices.id')
            ->whereHas('collection', fn ($q) => $q->whereIn('status', [
                DocumentStatus::CONFIRMED,
                DocumentStatus::CLOSED,
            ]));

        // sal_invoices.* না দিলে addSelect শুধু সাব-কোয়েরিটাই আনত
        return $query->addSelect(['sal_invoices.*', 'collected_total' => $collected]);
    }

    public function dueAmount(): string
    {
        $due = bcsub((string) $this->total, $this->collectedAmount(), 4);

        return bccomp($due, '0', 4) > 0 ? $due : '0.0000';
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

    public static function drillSourceType(): string
    {
        return 'sales_invoice';
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
        return ['sales.invoice.show', ['invoice' => $this->id]];
    }
}
