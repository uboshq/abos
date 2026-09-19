<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
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
class HandLoanAccount extends Model implements Drillable
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

    /**
     * ফেরতের ছাঁদ — কোডে, মাইগ্রেশনে নয়।
     *
     * ⓘ ধ্রুবকে রাখা হয় যাতে নতুন একটা ছাঁদ যোগ করতে ডাটাবেজ ছুঁতে না
     * হয়। ⚠️ enum কলাম হলে প্রতিটা নতুন ছাঁদে একটা মাইগ্রেশন লাগত, আর
     * পুরনো সারিগুলোর অর্থ বদলে যাওয়ার ঝুঁকি থাকত।
     */
    public const LUMP = 'lump';

    public const MONTHLY = 'monthly';

    public const WHENEVER = 'whenever';

    /** @var list<string> */
    public const REPAYMENTS = [self::LUMP, self::MONTHLY, self::WHENEVER];

    /**
     * কী কাগজে দাঁড়িয়ে আছে।
     *
     * ⛔ এই ঘরটা না থাকলে ছয় মাস পরে কেউ বলতে পারে না *"কাগজ ছিল কি
     * না"* — আর ঐ প্রশ্নটাই ওঠে ঠিক তখন, যখন সম্পর্কটা আর ভালো নেই।
     */
    public const VERBAL = 'verbal';

    public const STAMPED = 'stamped';

    public const BLANK_CHEQUE = 'blank_cheque';

    /** @var list<string> */
    public const SECURITIES = [self::VERBAL, self::STAMPED, self::BLANK_CHEQUE];

    protected $fillable = [
        'company_id', 'branch_id', 'person_id',
        'partner_id', 'partner_type', 'note', 'status', 'created_by',
        'interest_rate', 'term_months', 'due_on', 'repayment', 'security', 'next_due_on',
        'principal', 'opening_repaid', 'money_account_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal' => 'decimal:4',
            'opening_repaid' => 'decimal:4',
            'interest_rate' => 'decimal:4',
            'due_on' => 'date',
            'next_due_on' => 'date',
        ];
    }

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

    /*
     * ── Drillable — ⭐ কেন খাতাটা নিজেই, তার নড়াচড়া নয় ───────────────
     *
     * ⛔ ১৫ সেপ্টেম্বর ২০২৬ পর্যন্ত কেবল নড়াচড়ার সারিগুলো Drillable ছিল
     * (`hand_loan_movement` ধরনের), খাতাটা নয়।
     *
     * ⚠️ ফল: **কাগজ রাখার জায়গা ছিল না।** [[components/ui/attachments]]
     * `drillSourceType()` ধরে কাগজ খোঁজে, তাই FDR-এর সার্টিফিকেট,
     * ধারের স্ট্যাম্প বা ভাড়ার চুক্তিপত্র কোথাও তোলা যেত না — অথচ
     * ঝগড়া বাধলে ঐ কাগজটাই একমাত্র প্রমাণ।
     *
     * ⓘ কাগজ বসে **চুক্তিতে**, কিস্তিতে নয় — একটা FDR-এ একটাই
     * সার্টিফিকেট, যতবারই টাকা নড়ুক।
     */
    public static function drillSourceType(): string
    {
        return 'hand_loan';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no ?? (string) $this->id;
    }

    public function drillLabel(): string
    {
        return __('finance::menu.hand_loan').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.hand_loan.show', ['handLoan' => $this->id]];
    }
}
