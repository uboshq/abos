<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * এক কোম্পানি আরেকজনের হয়ে টাকা দিল — আর দুই খাতাই সেটা জানে।
 *
 * ── ⭐ মালিকের প্রশ্ন, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"গ্রুপের অ্যাকাউন্ট থেকে টাকা নিলে?"*
 *
 * ── ⓘ সারিটা কী ধরে রাখে ────────────────────────────────────────────
 * জোড়াটার একমাত্র সাক্ষী: কে দিল (`company_id`), কে পেল
 * (`counter_company_id`), কত, কেন, আর দুই পাশের ভাউচার কোনগুলো।
 *
 * ── ⚠️ দেয়ালটা কোথায়, আর কে পাহারা দেয় ─────────────────────────────
 * ⓘ [[BelongsToCompany]] `company_id` ছাঁকে, তাই তালিকা খুললে নিজের
 * কোম্পানির **দেওয়া** লেনদেনগুলোই আসে। ⛔ কিন্তু `counter_company_id`
 * কোনো স্কোপে পড়ে না — সেটাই একমাত্র ঘর যেটা সীমানা পেরোয়।
 *
 * ⚠️ তাই যাচাইটা সেবা স্তরে, মডেলে নয়:
 * [[InterCompanyService]] দেখে নেয় ব্যবহারকারী **দুইটা কোম্পানিতেই**
 * আছেন কি না। ⓘ মডেলে বসালে একটা `create()` কোথাও যাচাই এড়িয়ে যেতে
 * পারত, আর সেবা স্তরই একমাত্র দরজা।
 */
class InterCompanyTransfer extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_inter_company';

    protected $fillable = [
        'counter_company_id',
        'trx_date',
        'amount',
        'purpose',
    ];

    /**
     * ⚠️ `amount` অবশ্যই `decimal`, `float` নয়।
     *
     * ⓘ কলামটা `decimal(18,4)`; কাস্ট না দিলে PDO স্ট্রিং দেয় আর কেউ
     * `+` লিখলে PHP নিজেই float বানিয়ে ফেলে — তখন পয়সার ঘরে ভুল জমে,
     * আর সংখ্যাটা দেখতে সঠিকের মতোই লাগে।
     * ⛔ পাহারা দেয় [[MoneyIsNeverAFloatTest]]।
     */
    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    /** যে কোম্পানি টাকা দিল। */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** যে কোম্পানি পেল। */
    public function counterCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'counter_company_id');
    }

    /** দেওয়ার দিকের ভাউচার — টাকা যার খাতা থেকে বেরোল। */
    public function outVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'out_voucher_id');
    }

    /** পাওয়ার দিকের ভাউচার — যার খাতায় ঢুকল। */
    public function inVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'in_voucher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * দুই পাশ কি সত্যিই বসেছে?
     *
     * ⚠️ অবস্থাটা `confirmed` হওয়া যথেষ্ট নয় — দুইটা ভাউচারের
     * **দুইটাই** থাকতে হবে। ⓘ একটা বসে অন্যটা না বসলে খাতা মেলে না,
     * আর অবস্থার ঘরটা তখনো "হয়ে গেছে" বলত।
     */
    public function isBalanced(): bool
    {
        return $this->out_voucher_id !== null && $this->in_voucher_id !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }

    /*
     * ⛔ এখানে কোনো `kind()` পদ্ধতি নেই, আর সেটা ইচ্ছাকৃত।
     *
     * ⚠️ প্রথম লেখায় ছিল — সে পাওয়ার দিকের ভাউচার পড়ে বলত কাজটা টাকা
     * সরানো, খরচ দেওয়া, না দেনা মেটানো। ⓘ কিন্তু তালিকার পর্দা ৫০টা
     * সারি দেখায়, আর প্রতিটা সারিতে একটা করে কোয়েরি মানে **৫০টা
     * কোয়েরি** — ঠিক যে ভুলটা নিয়ে [[NumberSeriesEngine]]-এ ১৯২টা
     * কোয়েরির মন্তব্য লেখা আছে।
     *
     * ⭐ তাই উত্তরটা এখন [[InterCompanyService::kindsOf()]]-এ, যে গোটা
     * পাতার জন্য **একটাই** কোয়েরি করে। ⓘ মডেলে রেখে দিলে পরের কেউ
     * তালিকায় ওটাই ডাকত, আর ধীরগতিটা কোথাও লাল হত না।
     */
}
