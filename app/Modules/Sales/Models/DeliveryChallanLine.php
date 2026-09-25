<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** চালানের একটা লাইন — এক পণ্য, যত গেল। */
class DeliveryChallanLine extends Model
{
    use BelongsToCompanyThroughParent;
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_challan_lines';

    /*
     * ⚠️ `batch_id` তালিকায় না থাকলে সে **নীরবে** বাদ পড়ত — ⛔ Eloquent
     * `fillable`-এর বাইরের ঘর চুপচাপ ফেলে দেয়, কোনো ত্রুটি নয়।
     *
     * ⓘ ফল হত: বিক্রেতা লট বাছতেন, সেবা যাচাই করত, আর সারিটা চালানে
     * বসত লট ছাড়া — তারপর মাল বেরোত FEFO ধরে, অর্থাৎ **অন্য লট থেকে**।
     * ⚠️ কাগজে এক লট, গুদাম থেকে গেছে আরেকটা, আর ধরা পড়ত রিকলের দিন।
     */
    protected $fillable = [
        'delivery_challan_id', 'product_id', 'batch_id', 'sales_order_line_id',
        'delivered_qty', 'entered_qty', 'entered_unit_id',
        'free_qty', 'rate', 'discount_percent',
        'amount', 'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'delivered_qty' => 'decimal:4',
            'entered_qty' => 'decimal:4',
            'free_qty' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    /**
     * এই সারির কাগজ — আর তার `company_id`-ই এটাকে বাঁধে।
     *
     * ⓘ পুরো কারণটা [[BelongsToCompanyThroughParent]]-এ।
     */
    protected function companyParent(): string
    {
        return 'challan';
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * ⭐ বিক্রেতার বাছা লট — ২৫ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ `null` হলে মালটা FEFO ধরে বেরোবে, অর্থাৎ আগের আচরণ। ⚠️ সাধারণ
     * চালানে (অর্ডার থেকে, পোর্টাল থেকে) লট বাছা হয় না, আর হওয়ারও
     * দরকার নেই — কাউন্টারেই কেবল মানুষটা কার্টনটা হাতে ধরে দেখেন।
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class, 'delivery_challan_line_id');
    }

    /** এই লাইনের কতটুকু বিলে এসেছে। */
    public function invoicedQty(): string
    {
        $invoiced = $this->invoiceLines()
            ->whereHas('invoice', fn ($q) => $q->where('status', '<>', 'cancelled'))
            ->sum('qty');

        return (string) ($invoiced ?: '0');
    }

    public function uninvoicedQty(): string
    {
        $pending = bcsub((string) $this->delivered_qty, $this->invoicedQty(), 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }
}
