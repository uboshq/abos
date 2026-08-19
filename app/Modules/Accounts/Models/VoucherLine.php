<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ভাউচারের একটা সারি — একটা খাত, আর ডেবিট বা ক্রেডিট।
 *
 * BelongsToCompany নেই ইচ্ছাকৃতভাবে: সারিটা কখনো নিজে থেকে খোঁজা হয় না,
 * সবসময় তার ভাউচারের মধ্য দিয়ে। ভাউচারটাই স্কোপে বাঁধা, তাই সারিও।
 * এখানে আবার স্কোপ বসালে প্রতিটা join-এ একটা অতিরিক্ত শর্ত যোগ হত।
 */
class VoucherLine extends Model
{
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'voucher_id', 'account_id', 'party_type', 'party_id', 'cost_center_id',
        'debit', 'credit', 'narration', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** এই সারিতে টাকার অঙ্ক — যেদিকেই থাকুক। */
    public function amount(): string
    {
        return bccomp((string) $this->debit, '0', 4) > 0
            ? (string) $this->debit
            : (string) $this->credit;
    }
}
