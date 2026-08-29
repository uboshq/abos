<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * একজন মানুষের সাথে একটা চলমান হিসাব — ঋণ নয়।
 *
 * ── কেন সারি নয়, হিসাব ───────────────────────────────────────────────
 * পাঁচ হাজার দিলাম, দুই হাজার ফেরত এল, আরও তিন হাজার দিলাম — এটা তিনটা
 * ঋণ নয়, একটা সম্পর্ক আর তার একটা ব্যালেন্স। চলাচলগুলোর চিহ্ন থাকে,
 * আর ব্যালেন্স ওদের থেকেই বেরোয়। এতে "কে আমার কাছে পায়, আর আমি কার
 * কাছে পাই" একটাই তালিকা হয়।
 *
 * ── ব্যালেন্সের চিহ্ন কী বলে ─────────────────────────────────────────
 * ধনাত্মক — টাকাটা বাইরে আছে, তিনি ডিপোকে ফেরত দেবেন।
 * ঋণাত্মক — ডিপো তাঁর কাছে ধার নিয়েছে।
 */
class HandLoanAccount extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /** চলছে — টাকা বাইরে বা ভেতরে আছে */
    public const ACTIVE = 'active';

    /**
     * চুকে গেছে — মুছে ফেলা নয়।
     *
     * ইতিহাসটা থাকে, আর এখানে সেটা বিশেষভাবে দরকারি: "তুমি তো ফেরত
     * দাওনি" কথাটার উত্তর ওই পুরনো সারিগুলোই।
     */
    public const SETTLED = 'settled';

    protected $table = 'fin_hand_loan_accounts';

    protected $fillable = [
        'company_id', 'branch_id', 'person_name', 'mobile',
        'partner_id', 'partner_type', 'note', 'status', 'created_by',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(HandLoanMovement::class, 'account_id');
    }

    /** @param  Builder<HandLoanAccount>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    public function isSettled(): bool
    {
        return $this->status === self::SETTLED;
    }
}
