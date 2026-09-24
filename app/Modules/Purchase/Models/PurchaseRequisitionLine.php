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
 * চাহিদার একটা সারি — কী, কতটা, আর আন্দাজে কত।
 *
 * ── ⚠️ দরটা আন্দাজ, দাম নয় ──────────────────────────────────────────
 * ⓘ যিনি চান তিনি দর জানেন না, আর জানার কথাও নয়। ⛔ তবু একটা আন্দাজ
 * থাকলে অনুমোদনকারী বুঝতে পারেন কাগজটা দশ হাজারের না দশ লাখের — আর
 * সেটাই অনুমোদনের ছকের প্রশ্ন।
 *
 * ⚠️ এই সংখ্যাটা কোনোদিন ক্রয়াদেশে যায় না: ওখানে দর আসে সরবরাহকারীর
 * কাছ থেকে, আর দুইটা মিলিয়ে ফেললে আন্দাজটাই একদিন দাম হয়ে বসত।
 */
class PurchaseRequisitionLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_requisition_lines';

    protected $fillable = [
        'company_id', 'purchase_requisition_id', 'line_no',
        'product_id', 'qty', 'estimated_rate', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'estimated_rate' => 'decimal:4',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * ⓘ এই সারির আন্দাজি টাকা — পরিমাণ × আন্দাজি দর।
     *
     * ⚠️ দর না বসানো থাকলে শূন্য, ⛔ ধরে-নেওয়া কিছু নয়।
     */
    public function estimatedAmount(): string
    {
        return bcmul((string) $this->qty, (string) ($this->estimated_rate ?? '0'), 4);
    }
}
