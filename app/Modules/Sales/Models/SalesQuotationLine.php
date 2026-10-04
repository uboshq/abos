<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * উদ্ধৃতির একটা সারি।
 *
 * ⓘ ঘরগুলো আদেশের সারির মতোই, একটা বাড়তি: `header_share` — পুরো কাগজের
 * ছাড়ের এই সারির ভাগ। ⭐ আদেশে গেলে ওটা সারির ছাড়ে যোগ হয়, তাই আদেশের
 * মোট উদ্ধৃতির মোটের সাথে পয়সায় পয়সায় মেলে।
 */
class SalesQuotationLine extends Model
{
    use BelongsToCompanyThroughParent;
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_quotation_lines';

    protected $fillable = [
        'sales_quotation_id', 'product_id', 'qty',
        'entered_qty', 'entered_unit_id', 'rate',
        'discount', 'header_share', 'tax', 'tax_variance', 'amount', 'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'entered_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount' => 'decimal:4',
            'header_share' => 'decimal:4',
            'tax' => 'decimal:4',
            'tax_variance' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    /** ⓘ পুরো কারণটা [[BelongsToCompanyThroughParent]]-এ। */
    protected function companyParent(): string
    {
        return 'quotation';
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * ⛔ এই সারির অডিট কার খাতায় বসবে — উদ্ধৃতির কাছ থেকেই।
     *
     * ⓘ সারির নিজের `company_id` নেই। উত্তর না দিলে অডিট-ইঞ্জিন চলতি
     * প্রসঙ্গ থেকে আইডি নিত — সেটা যিনি কাজ করছেন তাঁর, সারিটার নয়।
     * ⭐ কাগজটা কার, উদ্ধৃতি নিজেই জানে।
     */
    public function auditCompanyId(): ?int
    {
        $id = $this->quotation?->company_id;

        return $id === null ? null : (int) $id;
    }

    /** ⓘ শাখাও উদ্ধৃতির — শাখাহীন উদ্ধৃতি হলে নাল, প্রসঙ্গ থেকে ধার নয়। */
    public function auditBranchId(): ?int
    {
        $id = $this->quotation?->branch_id;

        return $id === null ? null : (int) $id;
    }

    /** সারির মোট ছাড় — নিজের ছাড় আর পুরো কাগজের ছাড়ের ভাগ। */
    public function fullDiscount(): string
    {
        return bcadd((string) $this->discount, (string) $this->header_share, 4);
    }
}
