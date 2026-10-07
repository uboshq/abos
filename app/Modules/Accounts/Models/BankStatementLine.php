<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ব্যাংকের স্টেটমেন্টের একটা সারি — ব্যাংকের বক্তব্য, আমাদের খতিয়ান নয়।
 *
 * ── ⚠️ কেন এটা `LedgerEntry` নয় ─────────────────────────────────────
 * খতিয়ানের সারি মানে **আমরা বলছি এটা ঘটেছে**, আর তার পিছনে একটা দলিল
 * থাকে। ⛔ ব্যাংকের সারির পিছনে আমাদের কোনো দলিল নেই — ওটা কেবল খবর।
 * ⓘ দুইটা এক টেবিলে রাখলে একদিন কেউ যোগ করে ফেলত, আর স্থিতিপত্রে
 * ব্যাংকের কথা আমাদের কথা হয়ে বসত।
 *
 * ⭐ এই সারিগুলোর আসল কাজ একটাই প্রশ্নের উত্তর দেওয়া: **ব্যাংক যা জানে
 * অথচ আমরা জানি না, সেটা কী** ([[unmatched]])।
 */
class BankStatementLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_bank_statement_lines';

    protected $fillable = [
        'company_id', 'branch_id', 'bank_account_id',
        'trx_date', 'description', 'reference',
        'debit', 'credit', 'balance', 'fingerprint',
        'matched_line_id', 'matched_at', 'matched_by', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'matched_at' => 'datetime',

            /*
             * ⚠️ `decimal:4` — নাহলে মানটা string হয়ে ফেরে আর কেউ `+`
             * লিখলেই PHP float বানিয়ে ফেলে, আর টাকার হিসাবে float মানে
             * নীরব ভুল।
             */
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'balance' => 'decimal:4',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function matchedLine(): BelongsTo
    {
        return $this->belongsTo(VoucherLine::class, 'matched_line_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    /**
     * ⭐ ব্যাংক যা জানে, আমরা জানি না।
     *
     * ⓘ এই ছাঁকনিটাই পুরো পর্দার কারণ — বাকি সব সারি ইতিমধ্যেই আমাদের
     * বইয়ে আছে, তাই ওগুলো নিয়ে কারো কিছু করার নেই।
     */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('matched_line_id');
    }

    /** টাকার অঙ্ক, চিহ্নসহ — ব্যাংকের চোখে জমা ধনাত্মক */
    public function signedAmount(): string
    {
        return bcsub((string) $this->credit, (string) $this->debit, 4);
    }

    /**
     * ব্যাংক টাকা নিয়েছে, নাকি দিয়েছে।
     *
     * ⚠️ ব্যাংকের ভাষা আমাদের উল্টো: আমাদের খাতায় ব্যাংকে টাকা ঢুকলে
     * সেটা ডেবিট (সম্পদ বাড়ল), কিন্তু ব্যাংকের কাগজে ওটা ক্রেডিট —
     * কারণ ব্যাংকের কাছে আমরা তার **দায়**। ⓘ এই উল্টোটা মেলানোর সময়
     * সবচেয়ে বেশি ভুল করায়, তাই নামটা এখানে স্পষ্ট করে রাখা।
     */
    public function bankTookMoney(): bool
    {
        return bccomp((string) $this->debit, '0', 4) > 0;
    }
}
