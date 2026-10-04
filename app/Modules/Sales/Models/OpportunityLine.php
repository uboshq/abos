<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * সুযোগের একটা সারি — কোন পণ্য, আনুমানিক কত, কত টাকার।
 *
 * ⓘ দর নয়, মোট অঙ্ক: এই মুহূর্তে কেউ দর ঠিক করেননি, কেবল আন্দাজ।
 * দর ঠিক হয় কোটেশনে।
 */
class OpportunityLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_opportunity_lines';

    protected $fillable = [
        'company_id', 'opportunity_id', 'product_id', 'qty', 'value',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'value' => 'decimal:4',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
