<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * এক রান্নায় যে উপকরণটা যতটা গেল, আর তার দর।
 *
 * ── কেন দরটাও লেখা থাকে ─────────────────────────────────────────────
 * FIFO স্তর ফুরিয়ে যায়। আজ যে চাল ৬০ টাকায় গেছে, তিন মাস পরে ওই
 * স্তরটাই আর নেই — তখন "ওই দিন কত দরে গিয়েছিল" বের করার কোনো উপায়
 * থাকত না, আর খাদ্য-খরচের রিপোর্ট পিছনের তারিখে ভুল বলত।
 */
class ProductionLine extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $table = 'inv_production_lines';

    protected $fillable = [
        'company_id', 'production_id', 'product_id', 'qty', 'cost', 'sort',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'cost' => 'decimal:4',
        ];
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
