<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\SettledByAVoucher;
use App\Models\User;
/*
 * ⚠️ এই import-টা ছাড়া `Account::class` নিজের namespace-এ খোঁজা হত —
 * `App\Modules\Finance\Models\Account`, যেটা নেই। ⛔ ফল: মূলধনের পাতা
 * খুললেই ৫০০, আর ত্রুটিটা লাইভে ধরা পড়েছে, এখানে নয়।
 *
 * ⓘ Finance-এর বাকি চারটা মডেল (Deposit · DepositMovement ·
 * HandLoanMovement · Withdrawal) এটা আগে থেকেই লেখে — কেবল এই ফাইলটাই
 * বাদ পড়েছিল। ⭐ `accounts` module.php-এর `depends_on`-এ ঘোষিত, তাই
 * সীমানা ভাঙে না।
 */
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\MasterData\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কে ব্যবসায় টাকা দিলেন — মূলধন বা বিনিয়োগ।
 *
 * ── কেন দুইটা অবস্থা, আর কেবল দুইটা ─────────────────────────────────
 * `draft` — কথা হয়েছে, টাকা আসেনি। `posted` — টাকা এসেছে, খাতায় বসেছে।
 *
 * তৃতীয় কোনো অবস্থা নেই কারণ তৃতীয় কোনো ঘটনা নেই: হয় টাকাটা এসেছে,
 * নয় আসেনি। "বাতিল" রাখলে একটা না-আসা টাকার সারি চিরকাল তালিকায়
 * থেকে যেত, আর কেউ বলতে পারত না ওটা আসবে না কি ভুলে গেছে।
 */
class CapitalEntry extends Model implements Drillable, SettledByAVoucher
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    public const OWNER = 'owner';

    public const PARTNER = 'partner';

    public const INVESTOR = 'investor';

    /** @var list<string> */
    public const WHO = [self::OWNER, self::PARTNER, self::INVESTOR];

    /*
     * মূলধন আর বিনিয়োগের তফাত।
     *
     * মূলধন মালিকের নিজের টাকা — লাভ-লোকসান তাঁর। বিনিয়োগ বাইরের
     * কারও, আর তার শর্ত থাকে। খাতায় দুইটাই ইকুইটিতে বসে, কিন্তু
     * "কার টাকা কত" প্রশ্নে দুইটা আলাদা উত্তর — আর ওটাই অংশীদারি
     * ব্যবসার প্রথম ঝগড়া।
     */
    public const CONTRIBUTION = 'contribution';

    public const INVESTMENT = 'investment';

    /** @var list<string> */
    /*
     * মূলধন কী দিয়ে এল — নমুনার তিনটা পথ।
     *
     * ⛔ টাকা ছাড়া অন্য কিছু দিলে দাখিলার ডেবিট দিকটা বদলায়: যন্ত্রপাতি
     * গেলে স্থায়ী সম্পদে, পণ্য গেলে মজুদে। ⚠️ ঘরটা না থাকায় সবই টাকা
     * ধরা হত, আর একটা ট্রাক দিয়ে দেওয়া মূলধন নগদ হিসেবে বসত।
     */
    public const CASH = 'cash';

    public const ASSET = 'asset';

    public const GOODS = 'goods';

    /** @var list<string> */
    public const IN_KINDS = [self::CASH, self::ASSET, self::GOODS];

    public const KINDS = [self::CONTRIBUTION, self::INVESTMENT];

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    protected $table = 'acc_capital_entries';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'person_id', 'contributor_type',
        'entry_type', 'in_kind', 'trx_date', 'amount', 'share_percent', 'narration', 'status',
        'voucher_id', 'received_into_account_id', 'posted_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'amount' => 'decimal:4',
            'share_percent' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * কে দিলেন — নাম নয়, তালিকার সারি।
     *
     * ── ⛔ কেন নামটা সরানো হলো, ১৩ সেপ্টেম্বর ২০২৬ ───────────────────
     * আগে ঘরটা ছিল `contributor_name`, মুক্ত লেখা। মালিক নিজে প্রশ্নটা
     * করেছেন: *"একই মালিক আবার বিনিয়োগ করলে আবার নাম লিখতে হবে?"*
     *
     * দামটা টাইপ করার কষ্ট ছিল না। `CapitalService::positions()` দল
     * বাঁধত ওই নাম ধরে, তাই `Al Amin` ও `Al-Amin` **দুইজন অংশীদার** হয়ে
     * যেতেন — দুইজনের আলাদা নিট, আর দুইজনের আলাদা **অংশ %**। আর ওই
     * শতাংশটাই মুনাফা ভাগের হিসাব।
     *
     * ⓘ `contributor_type` (মালিক / অংশীদার) রয়ে গেছে, আর সেটা ঠিক —
     * ওটা **ভূমিকা**, পরিচয় নয়। একই মানুষ এক বছর অংশীদার, পরের বছর
     * মালিক হতে পারেন, আর তখনো তিনি একই সারি।
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    /**
     * কোন ভাউচারে খাতায় বসেছে — নিয়ম ১, "সংখ্যা থেকে কাগজে"।
     *
     * ── কেন এটা আজ পর্যন্ত ছিল না (১৩ সেপ্টেম্বর ২০২৬) ───────────────
     * `voucher_id` ঘরটা চিরকাল ছিল আর `post()` সেটা ভরত, কিন্তু সম্পর্কটা
     * ঘোষণা করা হয়নি — তাই `$entry->voucher` **চুপচাপ `null`** দিত।
     * ⚠️ কোনো ত্রুটি নয়: Eloquent অচেনা নামকে অনুপস্থিত অ্যাট্রিবিউট ধরে,
     * আর PHP `null->instrument_no` পড়তে গিয়েই তবে বাজে।
     *
     * ⓘ [[Withdrawal]]-এ ঠিক একই ঘর, আর ওখানে সম্পর্কটা আছে — অর্থাৎ
     * দুইটা প্রায়-একই নথি দুই রকম আচরণ করত, আর পার্থক্যটা কোথাও লেখা
     * ছিল না। ধরা পড়েছে লেনদেন নম্বরের পরীক্ষা লিখতে গিয়ে।
     *
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'received_into_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<CapitalEntry>  $query */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', self::POSTED);
    }

    // ── Drillable — নিয়ম ১, "সংখ্যা থেকে কাগজে" ───────────────────────

    /**
     * ⛔ এটা না থাকলে ড্রিল-লিংক ব্যতিক্রম ছুঁড়ত — আর সেটা আমি নিজেই
     * লাইভে পাঠিয়ে দিয়েছিলাম।
     *
     * ── কী ঘটেছিল, ১৪ সেপ্টেম্বর ২০২৬ ────────────────────────────────
     * `Finance/module.php`-এর `drill_sources`-এ `capital_entry` বসানো
     * হলো, কারণ [[App\Core\Contracts\SettledByAVoucher]]-এর হুকটা ঐ
     * মানচিত্র ধরেই ক্লাস খোঁজে। সেটা কাজ করেছে, আর ডিপ্লয়ও হয়েছে।
     *
     * ⚠️ কিন্তু একই মানচিত্র [[DrillResolver::resolve()]]-ও পড়ে, আর সে
     * `Drillable` না পেলে **জোরে থামে**:
     *
     *     "{$modelClass} is registered as drill source '{$sourceType}'
     *      but does not implement Drillable."
     *
     * ⓘ নিষ্পত্তির পথটা ভাঙত না — সে `map()` পড়ে, `resolve()` নয়। তাই
     * টাকা ঠিকই বসত, আর ভুলটা ধরা পড়ত কেবল যেদিন কেউ খতিয়ানের সারি
     * থেকে মূলধনের নথিতে ফিরতে চাইতেন। ⛔ একটা ৫০০, মাস পরে, আর কারণটা
     * ঐ দিনের কোনো কাজের সাথে মিলত না।
     *
     * ⭐ ধরা পড়েছে মেপে — পাঁচটা মডেলে `Drillable` আছে কি না গুনে দেখে,
     * চোখে পড়ে নয়।
     */
    public static function drillSourceType(): string
    {
        return 'capital_entry';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return __('finance::menu.capital').' — '.$this->document_no;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.capital.index', ['highlight' => $this->id]];
    }

    /**
     * রসিদটা পোস্ট হলো — টাকাটা এসে গেছে।
     *
     * ── ⭐ মালিকের কথা, ১৪ সেপ্টেম্বর ২০২৬ ───────────────────────────
     * *"ক্যাপিটাল থেকেই টাকা রিসিভ করার ব্যবস্থা করো।"*
     *
     * ⓘ অর্থাৎ শুরুটা এখান থেকে, কিন্তু টাকা গ্রহণের পর্দা একটাই —
     * রসিদ। আগে এই তালিকার প্রতিটা সারিতে একটা খাত-বাছাইয়ের ঘর গোঁজা
     * ছিল, আর সেখানে ব্যাংক, MFS, চার্জ, লেনদেন নম্বর — কিছুই চাওয়া
     * যেত না। ⛔ দুইটা আলাদা পথে টাকা ঢুকলে একদিন একটায় চার্জ বসত,
     * অন্যটায় না, আর কেউ ধরত না।
     *
     * ── ⚠️ কেন `where('status', DRAFT)` শর্তটা জরুরি ─────────────────
     * চুক্তিটা **idempotent** হতে বলে, আর কারণটা আসল: একটা রসিদ বাতিল
     * করে আবার পোস্ট করা যায়। শর্ত ছাড়া লিখলে দ্বিতীয়বার পোস্টে
     * `posted_at` বদলে যেত — অর্থাৎ **টাকাটা কবে এসেছিল সেই তারিখটাই
     * মিথ্যা হত**, আর ওটা ফিরে পাওয়ার কোনো উপায় থাকত না।
     *
     * ⓘ নিজের `save()` নয়, একটা শর্তযুক্ত `update()` — কারণ দুইটা
     * অনুরোধ একসাথে এলে প্রথমটাই জেতে, আর দ্বিতীয়টা শূন্য সারি বদলায়।
     */
    public function settleWith(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', self::DRAFT)
            ->update([
                'status' => self::POSTED,
                'voucher_id' => $voucherId,
                'posted_at' => now(),
            ]);

        $this->refresh();
    }

    /**
     * রসিদটা বাতিল হলো — সারিটা আবার খসড়া।
     *
     * ⚠️ শর্তে `voucher_id` মেলানো হয়, কারণ **অন্য কোনো ভাউচারের
     * বাতিল এই সারিটা খুলে দিতে পারবে না**। ⓘ শর্তটা না থাকলে একটা
     * ভুল `against_id` লেখা ভাউচার বাতিল করলে সম্পূর্ণ অন্য কারো
     * মূলধন আবার "আসেনি" হয়ে যেত, আর টাকাটা দ্বিতীয়বার চাওয়া হত।
     */
    public function unsettle(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', self::POSTED)
            ->where('voucher_id', $voucherId)
            ->update([
                'status' => self::DRAFT,
                'voucher_id' => null,
                'posted_at' => null,
            ]);

        $this->refresh();
    }
}
