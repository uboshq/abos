<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * মালিক বা অংশীদার ব্যবসা থেকে টাকা নিলেন।
 *
 * ── কেন এটা খরচ নয় ───────────────────────────────────────────────────
 * খরচ ব্যবসা চালাতে লাগে; উত্তোলন মালিকের নিজের টাকা নিয়ে যাওয়া। খরচ
 * লিখলে ব্যবসার মুনাফা কম দেখাত, আর বছরশেষে কে কত নিল তা বলার উপায়
 * থাকত না — অথচ অংশীদারি ব্যবসায় ওই সংখ্যাটাই সবচেয়ে বেশি দরকারি।
 */
class Withdrawal extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_withdrawals';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'contributor_name',
        'amount', 'trx_date', 'money_account_id', 'reason', 'status',
        'voucher_id', 'posted_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'trx_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** খাতায় বসেছে কি না — খসড়া মানে টাকা এখনো যায়নি। */
    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    /** @param  Builder<Withdrawal>  $query */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', DocumentStatus::CONFIRMED);
    }

    /**
     * এই মাসে গোনা হবে কি না।
     *
     * মাসিক সীমা কেবল বসে যাওয়া উত্তোলনেই খাটে। খসড়া গুনলে কেউ
     * কয়েকটা খসড়া লিখে রেখে সীমা ভরে ফেলতেন, অথচ টাকা যায়নি।
     */
    public function countsTowardsCap(): bool
    {
        return $this->status !== DocumentStatus::CANCELLED;
    }
}
