<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * চালানের একটা লাইন — এক পণ্য, যত এসেছে।
 */
class PurchaseReceiptLine extends Model
{
    use BelongsToCompanyThroughParent;
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_receipt_lines';

    protected $fillable = [
        'purchase_receipt_id', 'product_id', 'purchase_order_line_id',
        'received_qty', 'free_qty', 'entered_qty', 'entered_unit_id',
        'batch_no', 'expiry_date', 'mrp',
        'rate', 'sales_price', 'amount', 'tax', 'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'received_qty' => 'decimal:4',
            'free_qty' => 'decimal:4',
            'expiry_date' => 'date',
            'mrp' => 'decimal:4',
            'entered_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'sales_price' => 'decimal:4',
            'amount' => 'decimal:4',
            'tax' => 'decimal:4',
        ];
    }

    /**
     * এই সারির কাগজ — আর তার `company_id`-ই এটাকে বাঁধে।
     *
     * ⓘ পুরো কারণটা [[BelongsToCompanyThroughParent]]-এ।
     */
    protected function companyParent(): string
    {
        return 'receipt';
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function billLines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class, 'purchase_receipt_line_id');
    }

    /**
     * এই লাইনের কতটুকু বিলে এসেছে।
     *
     * এটাই ২১৬০ খাতটা শূন্যে ফেরার হিসাব: যতটুকু এসেছে তার সবটুকু বিল হলে
     * ওই লাইনের আর কিছু ঝুলে থাকে না।
     */
    public function billedQty(): string
    {
        $billed = $this->billLines()
            ->whereHas('bill', fn ($q) => $q->where('status', '<>', 'cancelled'))
            ->sum('qty');

        return (string) ($billed ?: '0');
    }

    public function unbilledQty(): string
    {
        $pending = bcsub((string) $this->received_qty, $this->billedQty(), 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }

    /**
     * ⭐ যে দর লেখা হয়েছিল — দামের ভেতরের ভ্যাটসহ (মালিক, ১০ অক্টোবর ২০২৬: "লেখা ১১৫, ভ্যাট বাদে ১০০, ভ্যাট ১৫ আলাদা")।
     *
     * ⓘ চালান দর ভ্যাট বাদে রাখে আর সারির ভ্যাট `tax`-এ (46088a75); লেখা দর = দর + ভ্যাট ÷ পরিমাণ, মূল এককে। ⛔ জমা থাকা
     * ভ্যাট থেকেই, পণ্যের আজকের হার থেকে নয়: লাইভের পুরনো সারিতে দর আগে থেকেই ভ্যাটসহ আর `tax` শূন্য — হার দিয়ে গুনলে ১১৫
     * হয়ে যেত ১৩২.২৫, আর হার বদলালে পুরনো চালানের দরও বদলাত।
     * ⓘ যেখানে দর আবার ফর্মে বা বিলে যায় (সম্পাদনা, বিল বানানো), সেখানে এটাই — ওরা নিজের নিয়মে ভ্যাট একবারই আলাদা করে।
     */
    public function typedRate(): string
    {
        if (bccomp((string) $this->tax, '0', 4) === 0 || bccomp((string) $this->received_qty, '0', 4) <= 0) {
            return (string) $this->rate;
        }

        return \App\Core\Support\Money::round(
            bcadd((string) $this->rate, bcdiv((string) $this->tax, (string) $this->received_qty, 10), 10), 4);
    }
}
