<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক মাসের ভাড়া — কতটা নগদে গেল, কতটা জামানত থেকে কাটল।
 *
 * ── কেন এই সারিটা আছে, যখন ভাউচারটাই সত্য ───────────────────────────
 * টাকার সত্য ভাউচারেই। ⚠️ কিন্তু *"এই চুক্তির কোন মাসগুলো করা হয়েছে"*
 * প্রশ্নটার উত্তর ভাউচার থেকে বের করা যায় না — খতিয়ানে মাসের নাম লেখা
 * থাকে না, আর একই খাতে পাঁচটা চুক্তির সারি মিশে থাকে।
 *
 * ⓘ তাই এই সারিটা টাকার দ্বিতীয় কপি নয়; সে কেবল বলে **কোন মাসটা করা
 * হয়েছে**, আর একই মাস দুইবার করা আটকায়।
 */
class RentalAdjustment extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'fin_rental_adjustments';

    protected $fillable = [
        'company_id', 'branch_id', 'rental_contract_id',
        'for_month', 'rent', 'paid_cash', 'from_deposit',
        'voucher_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'for_month' => 'date',
            'rent' => 'decimal:4',
            'paid_cash' => 'decimal:4',
            'from_deposit' => 'decimal:4',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** "সেপ্টেম্বর ২০২৬" — পর্দায় আর ছাপা কাগজে। */
    public function monthLabel(): string
    {
        return $this->for_month->translatedFormat('F Y');
    }
}
