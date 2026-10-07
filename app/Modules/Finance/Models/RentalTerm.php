<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ ভাড়ার এক দফা শর্ত — কোন মাস থেকে কত ভাড়া, জামানত থেকে কত কাটে (মালিকের সিদ্ধান্ত প্র১, ৬ অক্টোবর ২০২৬)।
 *
 * ⓘ চুক্তির মাসিক ভাড়া এখনকার দর; পুরনো মাসের দর এখান থেকে ([[RentalContract::rentFor()]]), তাই না-দেওয়া পুরনো মাস নিজের
 * দরে দেখায়। এক চুক্তিতে এক মাসে একটাই সারি — একই মাসে আবার বদলালে সেটাই হালনাগাদ।
 */
class RentalTerm extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_rental_terms';

    protected $fillable = [
        'company_id', 'branch_id', 'rental_contract_id', 'effective_from', 'monthly_rent', 'monthly_adjustment', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'monthly_rent' => 'decimal:4',
            'monthly_adjustment' => 'decimal:4',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }
}
