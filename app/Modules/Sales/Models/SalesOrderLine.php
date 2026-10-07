<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** আদেশের একটা লাইন। */
class SalesOrderLine extends Model
{
    use BelongsToCompanyThroughParent;
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_order_lines';

    protected $fillable = [
        'sales_order_id', 'product_id', 'ordered_qty',
        'entered_qty', 'entered_unit_id', 'rate',
        'discount', 'tax', 'tax_variance', 'amount', 'line_no', 'narration',
        // ⭐ DO বিক্রয় আদেশে মেশানো, ধাপ ১ — নকশার §১.৪ (মালিক, ৪ অক্টোবর ২০২৬)
        'requested_qty', 'free_qty', 'rejected_qty', 'reject_reason',
        'delivery_status', 'billing_status', 'line_status',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'entered_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'amount' => 'decimal:4',
            'requested_qty' => 'decimal:4',
            'free_qty' => 'decimal:4',
            'rejected_qty' => 'decimal:4',
            /*
             * ব্যতিক্রমের সংখ্যাগুলোও টাকা — তাই decimal, string নয়।
             *
             * cast না দিলে মানটা string হয়ে ফিরত, আর কেউ দুইটা সারির
             * পার্থক্য যোগ করতে গেলে PHP ওটাকে float বানিয়ে ফেলত।
             * এই রিপোতে টাকা কোনোদিন float হয় না ([[MoneyIsNeverAFloatTest]])।
             */
            'tax_variance' => 'decimal:4',
        ];
    }

    /**
     * এই সারির কাগজ — আর তার `company_id`-ই এটাকে বাঁধে।
     *
     * ⓘ পুরো কারণটা [[BelongsToCompanyThroughParent]]-এ।
     */
    /**
     * ⓘ অগ্রগতি কাগজের লেখা নয় — সইয়ের ছাপে গোনা হয় না ([[SalesOrder::fingerprintIgnores()]]-এর একই কারণ)।
     *
     * @return list<string>
     */
    public function fingerprintIgnores(): array
    {
        return ['delivery_status', 'billing_status', 'line_status'];
    }

    protected function companyParent(): string
    {
        return 'order';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function challanLines(): HasMany
    {
        return $this->hasMany(DeliveryChallanLine::class, 'sales_order_line_id');
    }

    /** এই লাইনের বিপরীতে এ পর্যন্ত কত মাল গেছে। */
    public function deliveredQty(): string
    {
        $delivered = $this->challanLines()
            ->whereHas('challan', fn ($q) => $q->where('status', '<>', 'cancelled'))
            ->sum('delivered_qty');

        return (string) ($delivered ?: '0');
    }

    /**
     * আর কত দেওয়া বাকি — ঋণাত্মক হয় না।
     *
     * ⭐ "আর দেওয়া হবে না" অংশ (`rejected_qty`) বাদ — তা আর পাওনা নয় (নকশা "DO বিক্রয় আদেশে মেশানো" §১.৪, ধাপ ৭)।
     */
    /**
     * যতটা এখনো আসলে বেরোয়নি — খসড়া চালানের অংশও এখানে (অডিট ম১৫, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ [[pendingQty()]] খসড়া চালানকে "দেওয়া" ধরে — অতিরিক্ত-চালানের পাহারার জন্য সেটাই ঠিক (খসড়া কোটা নেয়)। ⛔ কিন্তু
     * আদেশের ধরা মজুদ ছাড়তে সেটা ভুল: খসড়া কিছু বের করেনি, ধরাটা তখনো আদেশের। আদেশ বাতিল বা বন্ধে খসড়ার অংশ ধরা
     * থেকে যেত, আর খসড়াটা মুছলে চিরকাল আটকে থাকত। ⭐ এখানে কেবল নিশ্চিত (খসড়া নয়, বাতিল নয়) চালান বাদ যায়।
     */
    public function unshippedQty(): string
    {
        $shipped = (string) ($this->challanLines()
            ->whereHas('challan', fn ($q) => $q->whereNotIn('status', [DocumentStatus::DRAFT, DocumentStatus::CANCELLED]))
            ->sum('delivered_qty') ?: '0');
        $left = bcsub(bcsub((string) $this->ordered_qty, (string) ($this->rejected_qty ?? '0'), 4), $shipped, 4);

        return bccomp($left, '0', 4) > 0 ? $left : '0.0000';
    }

    public function pendingQty(): string
    {
        $pending = bcsub(bcsub((string) $this->ordered_qty, (string) ($this->rejected_qty ?? '0'), 4), $this->deliveredQty(), 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }
}
