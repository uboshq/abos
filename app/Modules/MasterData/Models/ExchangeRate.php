<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক দিনের বিনিময় হার।
 *
 * পুরনো সারি বদলানো হয় না — নতুন তারিখে নতুন সারি বসে। তাই গত মাসের
 * বিলটা আজ খুললেও ওই মাসের হারেই দেখা যায়, আর "হারটা কে কবে বদলাল"
 * প্রশ্নের উত্তর টেবিলেই থাকে।
 */
class ExchangeRate extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'mdm_exchange_rates';

    protected $fillable = [
        'company_id', 'currency_id', 'effective_from', 'rate', 'source', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'rate' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
