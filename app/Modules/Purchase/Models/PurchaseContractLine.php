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
 * চুক্তির একটা সারি — একটা পণ্য, একটা দর, আর সীমা।
 *
 * ── ⚠️ সীমা দুইটা, আর দুইটাই ঐচ্ছিক ─────────────────────────────────
 * ⓘ কিছু চুক্তি বলে *"এই দরে দশ টন দেব"*, কিছু বলে *"এই দরে পাঁচ লাখ
 * টাকার মাল দেব"*। ⛔ একটামাত্র সীমা রাখলে দ্বিতীয় জাতের চুক্তিটা
 * লেখাই যেত না, আর মানুষ পরিমাণের ঘরে টাকার অঙ্ক বসাতেন।
 */
class PurchaseContractLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_contract_lines';

    protected $fillable = [
        'company_id', 'contract_id', 'line_no', 'product_id',
        'agreed_rate', 'qty_limit', 'value_limit', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'agreed_rate' => 'decimal:4',
            'qty_limit' => 'decimal:4',
            'value_limit' => 'decimal:4',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(PurchaseContract::class, 'contract_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
