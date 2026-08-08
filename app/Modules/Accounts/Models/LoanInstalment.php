<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * একটা কিস্তি — তারিখ, আসল, সুদ।
 *
 * ── কেন সূচিটা সারি হয়ে থাকে, চাহিদামতো গোনা হয় না ──────────────────
 * ব্যাংকের কাগজে কিস্তিগুলো ছাপা থাকে, আর সেগুলোই সত্য। প্রতিবার নতুন
 * করে গুণলে পয়সার ভগ্নাংশে ব্যাংকের সাথে অমিল হত, আর তখন কোনটা ঠিক তা
 * বলার উপায় থাকত না।
 *
 * সারি হিসেবে থাকায় ব্যাংক কোনো কিস্তি বদলালে (পুনঃতফসিল, আংশিক
 * পরিশোধ) সেই সারিটাই শোধরানো যায়।
 */
class LoanInstalment extends Model
{
    use HasFactory;

    public const DUE = 'due';

    public const PAID = 'paid';

    protected $table = 'acc_loan_instalments';

    protected $fillable = [
        'loan_id', 'no', 'due_date', 'principal', 'interest',
        'paid_amount', 'paid_on', 'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'paid_on' => 'date',
            'principal' => 'decimal:4',
            'interest' => 'decimal:4',
            'paid_amount' => 'decimal:4',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** এই কিস্তিতে ব্যাংক যত টাকা নেবে — আসল আর সুদ একসাথে। */
    public function total(): string
    {
        return bcadd((string) $this->principal, (string) $this->interest, 4);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /**
     * তারিখ পেরিয়ে গেছে অথচ দেওয়া হয়নি।
     *
     * এই একটা প্রশ্নের উত্তরই ঋণের পাতায় সবচেয়ে বেশি খোঁজা হয় — কারণ
     * পেরোনো কিস্তিতে ব্যাংক জরিমানা বসায়, আর সেটা কেউ মনে করিয়ে না
     * দিলে চোখেই পড়ে না।
     */
    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date?->isBefore(Carbon::today());
    }
}
