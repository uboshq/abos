<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ ক্যাশবাক্সের দায়িত্ব হস্তান্তর — আগের জন থেকে নতুন জন, জের গুনে, সই নিয়ে (Accounts-Finance অডিট ম৮, ৪ অক্টোবর ২০২৬)।
 *
 * ⓘ খাতায় কিছু বসে না — টাকা একই বাক্সে থাকে, বদলায় কেবল তার দায়। গুনে কম-বেশি হলে পার্থক্যটা নগদ গণনার কাগজে যায়
 * (`cash_count_id`), নিজের কোনো হিসাব লেখে না ([[TillHandoverService]])।
 *
 *   `awaiting`  — সইয়ের অপেক্ষায়; বাক্স এখনো আগের জনের
 *   `confirmed` — নতুন জন দায় নিয়েছেন
 *   `cancelled` — সই ফেরত বা বাতিল; বাক্স আগের জনেরই
 */
class TillHandover extends Model
{
    use BelongsToCompany;
    // ⛔ শাখার দেয়াল — হেডারের শাখা আর মানুষের নাগাল (অডিট ⛔৪, ৬ অক্টোবর ২০২৬; [[ScopedToUserBranch]])
    use ScopedToUserBranch;
    use HasPublicId;
    use IsAudited;

    public const AWAITING = 'awaiting';

    protected $table = 'acc_till_handovers';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'trx_date', 'cash_till_id',
        'from_holder_id', 'to_holder_id', 'book_balance', 'counted_amount', 'difference', 'cash_count_id',
        'narration', 'status', 'created_by', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'book_balance' => 'decimal:4',
            'counted_amount' => 'decimal:4',
            'difference' => 'decimal:4',
            'confirmed_at' => 'datetime',
        ];
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(CashTill::class, 'cash_till_id');
    }

    public function fromHolder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_holder_id');
    }

    public function toHolder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_holder_id');
    }

    public function cashCount(): BelongsTo
    {
        return $this->belongsTo(CashCount::class, 'cash_count_id');
    }

    public function isAwaiting(): bool
    {
        return $this->status === self::AWAITING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }
}
