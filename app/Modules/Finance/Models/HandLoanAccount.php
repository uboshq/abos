<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\MasterData\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'company_id', 'branch_id', 'person_id',
        'partner_id', 'partner_type', 'note', 'status', 'created_by',
    ];

    /**
     * কার সাথে — নাম নয়, তালিকার সারি।
     *
     * ⓘ মোবাইলের ঘরটাও এখানে ছিল, আর সেটাও ব্যক্তির সারিতে চলে গেছে।
     * দুই জায়গায় নম্বর রাখলে একদিন আলাদা হত, আর তখন নকল-পাহারা পুরনো
     * নম্বর ধরে কাজ করে একই মানুষকে দুইবার বসতে দিত।
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

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
