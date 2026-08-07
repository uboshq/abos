<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা নথি একটা স্তর থেকে যতটুকু টেনেছে।
 *
 * এই সারিগুলোই "৫৫০ টাকা খরচ কোথা থেকে এল" প্রশ্নের উত্তর। একটা
 * বিক্রয়ে দুইটা চালানের মাল গেলে দুইটা সারি হয়, আর দুইটার দাম আলাদা —
 * যোগফলটাই ওই বিক্রয়ের খরচ।
 */
class CostLayerUse extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'inv_cost_layer_uses';

    protected $fillable = [
        'company_id', 'cost_layer_id', 'product_id', 'source_type', 'source_id',
        'document_no', 'trx_date', 'qty', 'unit_cost', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(CostLayer::class, 'cost_layer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
