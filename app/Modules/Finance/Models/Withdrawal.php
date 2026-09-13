<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\MasterData\Models\Person;
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
        'company_id', 'branch_id', 'document_no', 'person_id',
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

    /**
     * কে তুললেন — নাম নয়, তালিকার সারি।
     *
     * ── ⛔ কেন, আর এখানে দামটা সবচেয়ে বেশি ──────────────────────────
     * এই ঘরটা মাসিক সীমার সাথে মেলাতে হয় (`WithdrawalService::assertWithinCap`),
     * আর মিলটা ছিল **হুবহু নাম ধরে**। দুইভাবে টাকা বেরিয়ে যেত:
     *
     *   ১। সীমাটাই খুঁজে পাওয়া যেত না → চুপচাপ কিছুই আটকাত না
     *   ২। সীমা পাওয়া গেলেও "এই মাসে কত তোলা হয়েছে" গোনাটা ভিন্ন
     *      বানানের সারিগুলো বাদ দিত → সীমা বসত ভুল (কম) মোটের উপর,
     *      আর পর্দা বলত "সীমার ভেতরে আছেন"
     *
     * ⚠️ দ্বিতীয়টা প্রথমটার চেয়ে খারাপ: প্রথমটায় সীমা কাজ করে না, যা
     * অন্তত ধারাবাহিক। দ্বিতীয়টায় সীমা **কাজ করছে বলে মনে হয়**, আর
     * "বাকি আছে এতটা" সংখ্যাটা মিথ্যা — মানুষ ওই সংখ্যা দেখে সিদ্ধান্ত নেন।
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
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
