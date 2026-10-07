<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DO-র এক লাইন — `qty` যা চাওয়া হলো, `approved_qty` সুপারভাইজার মজুদ দেখে যা দিলেন ([[finalQty()]])।
 * ⓘ লট নেই — ডিলার বা SR লট জানেন না; লট বাছা ডিপোর যাচাইয়ে (abos-bb)।
 */
class DeliveryOrderLine extends Model
{
    // ⓘ কোম্পানির দেয়াল কাগজ থেকে; সুপারভাইজারের পরিমাণ-বদল অডিটে থাকে (কে কত কমালেন)
    use BelongsToCompanyThroughParent;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_delivery_order_lines';

    protected $fillable = [
        'delivery_order_id', 'sales_order_line_id', 'product_id',
        'qty', 'approved_qty', 'rate', 'discount_percent', 'free_qty', 'line_total', 'note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'approved_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'free_qty' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    protected function companyParent(): string
    {
        return 'deliveryOrder';
    }

    /** ⓘ অডিট-সারি DO-র খাতায় — লাইনের নিজের company_id নেই ([[AnAuditedModelMustSayWhoseBooksItBelongsToTest]]) */
    public function auditCompanyId(): ?int
    {
        return $this->deliveryOrder?->company_id;
    }

    /** ⓘ একই কারণে শাখাও — DO কোন শাখার, সে-ই জানে */
    public function auditBranchId(): ?int
    {
        return $this->deliveryOrder?->branch_id;
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** ⭐ চূড়ান্ত পরিমাণ — সুপারভাইজার বদলালে সেটা, নাহলে যা চাওয়া হয়েছিল */
    public function finalQty(): string
    {
        return (string) ($this->approved_qty ?? $this->qty);
    }

    /** চূড়ান্ত পরিমাণ × দর − ছাড়% (ফ্রির টাকা নেই) */
    public function computedTotal(): string
    {
        $gross = bcmul($this->finalQty(), (string) $this->rate, 4);
        $off = bcdiv(bcmul($gross, (string) ($this->discount_percent ?? '0'), 6), '100', 4);

        return bcsub($gross, $off, 4);
    }
}
