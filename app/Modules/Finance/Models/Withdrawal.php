<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\SettledByAVoucher;
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
class Withdrawal extends Model implements Drillable, SettledByAVoucher
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

    /**
     * পরিশোধ ভাউচারটা পোস্ট হলো — টাকাটা সত্যিই বেরিয়ে গেছে।
     *
     * ── ⛔ কেন এটা এতদিন ছিল না ─────────────────────────────────────
     * `voucher_id` ঘরটা আর `voucher()` সম্পর্কটা **আগে থেকেই** ছিল,
     * কিন্তু ভরার কেউ ছিল না। ⚠️ অর্থাৎ ভাউচার পোস্ট হত, টাকা খাতায়
     * বসত, আর উত্তোলনের সারিটা **চিরকাল খসড়া** থেকে যেত — কোনো
     * ত্রুটি ছাড়াই, কারণ কোথাও কিছু ভাঙত না।
     *
     * ⓘ [[CapitalEntry::settleWith()]]-এর হুবহু ছাঁচ, আর সেটাই উদ্দেশ্য:
     * একই নিয়মের দুইটা বাস্তবায়ন থাকলে ওরা একদিন আলাদা উত্তর দেয়।
     *
     * ⚠️ শর্তযুক্ত `update()`, নিজের `save()` নয় — চুক্তিটা
     * **idempotent** হতে বলে ([[SettledByAVoucher]])। একটা ভাউচার বাতিল
     * করে আবার পোস্ট করা যায়; শর্ত ছাড়া লিখলে `posted_at` বদলে যেত,
     * অর্থাৎ **টাকাটা কবে গিয়েছিল সেই তারিখটাই মিথ্যা হত**।
     */
    public function settleWith(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', DocumentStatus::DRAFT)
            ->update([
                'status' => DocumentStatus::CONFIRMED,
                'voucher_id' => $voucherId,
                'posted_at' => now(),
            ]);

        $this->refresh();
    }

    /**
     * ভাউচারটা বাতিল হলো — সারিটা আবার খসড়া।
     *
     * ⚠️ শর্তে `voucher_id` মেলানো হয়, কারণ **অন্য কোনো ভাউচারের বাতিল
     * এই সারিটা খুলে দিতে পারবে না**। ⓘ না মিলালে একটা ভুল
     * `against_id` লেখা ভাউচার বাতিল করলে সম্পূর্ণ অন্য কারো উত্তোলন
     * আবার "হয়নি" হয়ে যেত — আর মাসিক সীমার হিসাবও তাতে ভুল হত
     * ([[countsTowardsCap()]])।
     */
    public function unsettle(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', DocumentStatus::CONFIRMED)
            ->where('voucher_id', $voucherId)
            ->update([
                'status' => DocumentStatus::DRAFT,
                'voucher_id' => null,
                'posted_at' => null,
            ]);

        $this->refresh();
    }

    // ── Drillable — নিয়ম ১, "সংখ্যা থেকে কাগজে" ───────────────────────

    /**
     * ⛔ `drill_sources`-এ নাম বসানোই যথেষ্ট নয় — ক্লাসটাকেও ড্রিল করা
     * যেতে হবে।
     *
     * ── কী ধরা পড়েছিল, ১৪ সেপ্টেম্বর ২০২৬ ────────────────────────────
     * নিষ্পত্তির হুকের জন্য (`SettledByAVoucher`) নামটা মানচিত্রে বসানো
     * হলো, আর সেটা কাজও করল — কারণ [[VoucherService]] `map()` সরাসরি পড়ে।
     *
     * ⚠️ কিন্তু একই মানচিত্র [[DrillResolver::resolve()]]-ও পড়ে, আর সে
     * `Drillable` না পেলে **ব্যতিক্রম ছোঁড়ে**। ⓘ অর্থাৎ টাকা ঠিকই বসত,
     * সব সবুজ দেখাত, আর ভুলটা ধরা পড়ত সেদিন — মাস পরে — যেদিন কেউ
     * খতিয়ানের একটা সারি থেকে এই নথিতে ফিরতে চাইতেন।
     *
     * ⭐ ধরা পড়েছে [[EveryDrillSourceCanActuallyBeDrilledIntoTest]]-এ,
     * চোখে নয় — মানচিত্রের প্রতিটা নাম গুনে দেখে।
     *
     * মালিক কত তুললেন — নিজের নথি, নিজের নম্বর।
     */
    public static function drillSourceType(): string
    {
        return 'withdrawal';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return __('finance::menu.withdrawal').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.withdrawal.index', ['highlight' => $this->id]];
    }
}
