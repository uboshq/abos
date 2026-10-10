<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\SettledByAVoucher;
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
class HandLoanMovement extends Model implements Drillable, SettledByAVoucher, \App\Core\Contracts\SettlementTerms
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
        // ⭐ কবে ফেরতের কথা — পরিকল্পনা ১.৮, ৫ অক্টোবর ২০২৬ ([[HandLoanReports::SCHEDULE]])
        'return_on',
        'money_account_id', 'voucher_id', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'moved_on' => 'date',
            'return_on' => 'date',
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

    /**
     * ⭐ কোন চলাচল গোনা হয় — একটাই নিয়ম, তিন জায়গায়: হিসাবের বকেয়া ([[HandLoanService::balanceOf()]]), মানুষের তালিকা
     * ([[HandLoanService::people()]]) আর মানুষের খাতা ([[LoanLedgerReports]], [[countedSql()]])।
     *
     * ⛔ কেবল খাতায় বসা টাকা — অডিট গ১ ও ম২২, ৪ অক্টোবর ২০২৬: সই-এর অপেক্ষার (খসড়া) ভাউচারের টাকা এখনো নড়েনি, আর
     * বাতিল ভাউচারের টাকা ফিরে এসেছে। ⚠️ ভাউচার ছাড়া পুরনো সারি (খোলার আমদানি) গোনা হয়।
     */
    public function scopeCounted(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        // ⛔ ভাউচারের শাখা-দেয়াল ছাড়া — ভাউচার বসে হিসাবের নিজের শাখায়; হেডারে অন্য শাখা বাছা থাকলে দেয়াল সেটাকে বাদ দিত আর জের
        // ভুল হত (cloud/finance-fixes রিভিউ ⚠️১, ১০ অক্টোবর ২০২৬)। কোন হিসাব দেখা যায় তা তালিকা ঠিক করে ([[HandLoanAccount::inViewedBranch]])।
        return $query->where(fn ($q) => $q->whereNull('voucher_id')
            ->orWhereHas('voucher', fn ($v) => $v->withoutGlobalScope('user-branch')->where('status', \App\Core\Support\DocumentStatus::CONFIRMED)));
    }

    /**
     * [[scopeCounted()]]-এর হুবহু শর্ত, কাঁচা কোয়েরির জন্য — চলাচল `$movement` আর তার ভাউচার `$voucher` (LEFT JOIN) নামে।
     * ⛔ এখানে আলাদা করে লেখা নিয়মটা ঐ স্কোপের পাশেই, যাতে একটা বদলালে অন্যটা চোখে পড়ে; দুইটা মেলে কি না দাবিতে বাঁধা
     * ([[TheHandLoanBookEndsWhereTheListSaysTest]])।
     */
    public static function countedSql(string $movement, string $voucher): string
    {
        return "({$movement}.voucher_id IS NULL OR {$voucher}.status = '".\App\Core\Support\DocumentStatus::CONFIRMED."')";
    }

    /** ডিপোর দিক থেকে চিহ্নসহ — ব্যালেন্স এদের যোগফল। */
    public function signed(): string
    {
        return $this->direction === self::OUT
            ? (string) $this->amount
            : bcmul((string) $this->amount, '-1', 4);
    }

    /**
     * ভাউচারটা পোস্ট হলো — ধারের এই গতিবিধির টাকাটা সত্যিই নড়েছে।
     *
     * ── ⚠️ এই টেবিলে `status` নেই, তাই নিষ্পত্তির চিহ্ন `voucher_id` ──
     * [[CapitalEntry]] ও [[Withdrawal]]-এ অবস্থা বদলায়; এখানে সারিটা
     * জন্মায়ই ঘটনা হিসেবে। ⭐ তাই পাহারাটা `whereNull()`, আর চুক্তির
     * দুইটা শর্তই মানা হয় ([[SettledByAVoucher]])।
     *
     * ── ⛔ এক সারি দুই দিকে যায়, আর সেখানেই ফাঁদ ────────────────────
     * `direction` ঘরটা বলে টাকা গেছে (`out`) না এসেছে (`in`) — অর্থাৎ
     * একই টেবিলের সারি কখনো পরিশোধ ভাউচারে, কখনো রসিদে বাঁধে।
     *
     * ⚠️ `whereNull` ছাড়া লিখলে ভুল দিকের একটা ভাউচার একই সারিকে
     * পুনরায় বেঁধে ফেলত — আর তখন ধার শোধের টাকাটা ধার নেওয়া হিসেবে
     * দেখাত, বকেয়া দ্বিগুণ হয়ে।
     */
    public function settleWith(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->whereNull('voucher_id')
            ->update(['voucher_id' => $voucherId]);

        $this->refresh();
    }

    /** ভাউচার বাতিল — জোড়াটা খোলে, সারিটা থাকে। */
    public function unsettle(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('voucher_id', $voucherId)
            ->update(['voucher_id' => null]);

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
     * ধারের কিস্তির নিজের নম্বর নেই — ধারের হিসাবটাই মানুষ চেনে।
     */
    public static function drillSourceType(): string
    {
        return 'hand_loan_movement';
    }

    public function drillDocumentNo(): string
    {
        return $this->account?->document_no ?? (string) $this->id;
    }

    public function drillLabel(): string
    {
        return __('finance::menu.hand_loan').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        /*
         * ⛔ `hand_loan_account_id` নয় — কলামটার নাম `account_id`।
         *
         * ⚠️ প্রথমে ভুল নামটাই লেখা হয়েছিল, আর Eloquent তাতে **কিছুই
         * ছোঁড়ে না**: অচেনা নামকে সে অনুপস্থিত অ্যাট্রিবিউট ধরে নেয়,
         * তাই `null` ফেরাত। ⓘ ফল হত একটা লিংক যেটা কোথাও যায় না —
         * কোনো ত্রুটি ছাড়া, আর ধরা পড়ত মাস পরে।
         *
         * ⭐ ধরেছে অর্থের সেশন, কলামের তালিকা মিলিয়ে। আমার নিজের
         * [[EveryDrillSourceCanActuallyBeDrilledIntoTest]] এটা ধরত না —
         * সে দেখত ক্লাসটা `Drillable` কি না, রুটের ঘরগুলো সত্যি কি না
         * তা নয়। ⓘ সেই ফাঁকটাও এখন বন্ধ।
         */
        return ['finance.hand_loan.show', ['handLoan' => $this->account_id]];
    }

    /**
     * ⭐ গ২ — কোন ভাউচার এই কাগজটা মেটাতে পারে ([[SettlementTerms]], Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে লুকানো "বিপরীতে" ঘরে যা লেখা হত তা-ই মানা হত — অঙ্ক, পক্ষ, ধরন, অবস্থা কিছুই দেখা হত না।
     */
    public function settlementTerms(): array
    {
        return [
            'voucher_type' => $this->direction === self::OUT ? 'payment' : 'receipt',
            'amount' => (string) $this->amount,
            'open' => $this->voucher_id === null,
        ];
    }
}
