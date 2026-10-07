<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * উপহারটা সত্যিই বেরিয়েছে — কোন গুদাম থেকে, কোন লট থেকে।
 *
 * ⚠️ সারিটা কখনো মোছা হয় না। ⓘ ফেরত এলে `returned_qty` বাড়ে, সারিটা
 * থেকে যায় — কারণ মোছা মানে ইতিহাস মোছা, আর §১৯ বলে নিরীক্ষার খাতা
 * কখনো মোছা যাবে না।
 */
class PromotionGiftIssue extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'promotion_application_id', 'product_id', 'warehouse_id', 'batch_id',
        'qty', 'unit_id', 'unit_cost', 'issued_by', 'issued_at', 'returned_qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'returned_qty' => 'decimal:4',
            'issued_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PromotionApplication::class, 'promotion_application_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * ⓘ এই উপহারে যাওয়া পিসগুলো — মজুদের নকশায় যোগটা পিসের সারিতে
     * (`out_source_type` + `out_source_id`), উপহারের কাগজে নয়।
     */
    public function serials(): HasMany
    {
        return $this->hasMany(SerialNumber::class, 'out_source_id')->where('out_source_type', 'promotion:gift');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * ⭐ ক্রেতার কাছে এখনো কতটা আছে।
     *
     * ⓘ দেওয়া হয়েছিল পাঁচ, ফেরত এসেছে দুই — ক্রেতার কাছে তিন।
     * ⚠️ §১৮-এর যোগ্যতা আবার হিসাব করার সময় এই সংখ্যাটাই লাগে,
     * `qty` নয়।
     */
    public function stillWithTheBuyer(): string
    {
        return bcsub((string) $this->qty, (string) $this->returned_qty, 4);
    }

    /**
     * ⛔ মালিকের খরচ — ক্রেতার প্রাপ্তি নয়।
     *
     * ⓘ §১৭-এর *"Promotion Cost"* এই সংখ্যাটা ধরে গোনা হয়। ⚠️ বিক্রয়মূল্য
     * ধরলে খরচটা ফুলে দেখাত, আর একটা ভালো অফারও কাগজে খারাপ মনে হত।
     */
    public function cost(): string
    {
        return bcmul($this->stillWithTheBuyer(), (string) $this->unit_cost, 4);
    }
}
