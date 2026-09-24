<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা দরের একটা সারি।
 *
 * ── ⓘ ছাড় ও কর সারিতেই, কাগজের মাথায় নয় ────────────────────────────
 * সরবরাহকারীরা পণ্য ধরে ধরে ছাড় দেন — কোনোটায় দশ শতাংশ, কোনোটায়
 * কিছুই না। ⚠️ একটা মোট ছাড় বসালে কোন পণ্যে কত ছাড় তা হারাত, অথচ
 * পরের বার দরাদরিতে ঠিক ওটাই লাগে।
 */
class QuotationLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_quotation_lines';

    protected $fillable = [
        'company_id', 'quotation_id', 'line_no', 'product_id',
        'qty', 'rate', 'discount', 'tax', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * এই সারির টাকা — পরিমাণ × দর − ছাড় + কর।
     *
     * ⓘ ক্রমটা গুরুত্বপূর্ণ: ⚠️ ছাড়ের **পরে** কর বসে, কারণ কর বসে যা
     * সত্যিই দিতে হবে তার উপর। ⛔ উল্টো করলে প্রতিটা সারিতে একটু বেশি
     * কর গোনা হত, আর তুলনায় সস্তা দরটা মিথ্যা দামি দেখাত।
     */
    public function netAmount(): string
    {
        $gross = bcmul((string) $this->qty, (string) $this->rate, 4);

        return bcadd(bcsub($gross, (string) $this->discount, 4), (string) $this->tax, 4);
    }
}
