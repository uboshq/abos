<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * টাকা গেল, নাকি এল — আর এটাই গোটা মডেল।
 *
 * ── কেন চারটা ধরন নয়, দুইটা দিক ──────────────────────────────────────
 * "ধার দিলাম · ধার নিলাম · ফেরত দিলাম · ফেরত পেলাম" — চারটা ধরন লিখলে
 * সাথে একটা নিয়মও লাগত: কোনটার পরে কোনটা আসতে পারে। আর সেই নিয়মটা
 * প্রথমবারেই ভুল হয়, যখন আগেরটা ফেরত আসার আগেই কেউ আবার ধার নেন।
 *
 * চিহ্ন দিয়ে চললে ওই প্রশ্নটাই ওঠে না। **ঘটনাটার মানে ব্যালেন্স বলে,
 * চলাচল নয়।**
 */
class HandLoanMovement extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /** টাকা এখান থেকে গেল — ধার দিলাম, বা তাঁর টাকা ফেরত দিলাম */
    public const OUT = 'out';

    /** টাকা এখানে এল — ধার নিলাম, বা তিনি ফেরত দিলেন */
    public const IN = 'in';

    /** @var list<string> */
    public const DIRECTIONS = [self::OUT, self::IN];

    protected $table = 'fin_hand_loan_movements';

    protected $fillable = [
        'company_id', 'account_id', 'direction', 'amount', 'moved_on',
        'money_account_id', 'voucher_id', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'moved_on' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(HandLoanAccount::class, 'account_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** ডিপোর দিক থেকে চিহ্নসহ — ব্যালেন্স এদের যোগফল। */
    public function signed(): string
    {
        return $this->direction === self::OUT
            ? (string) $this->amount
            : bcmul((string) $this->amount, '-1', 4);
    }
}
