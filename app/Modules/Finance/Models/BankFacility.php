<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ব্যাংকের একটা সুবিধা — মঞ্জুরি, জামানত, নবায়ন।
 *
 * ── কেন এটা [[HandLoanAccount]]-এর সাথে মেলানো গেল না ─────────────────
 * হাতধারে যা একটাও নেই: মঞ্জুরিপত্র · জামানত · ড্রয়িং পাওয়ার · বার্ষিক
 * নবায়ন · শর্ত ভাঙলে জরিমানা। ⓘ আর ব্যাংকের সুবিধা পাঁচ রকম, প্রতিটার
 * আচরণ আলাদা।
 *
 * ── ⭐ পাঁচটার আসল পার্থক্য একটাই প্রশ্নে ────────────────────────────
 * **টাকাটা কোথায় থাকে?**
 *
 *     CC / OD      ব্যাংক হিসাবে — এখানে কেবল মঞ্জুরির নথি
 *     মেয়াদি ঋণ    আমাদের হিসাবে ঢোকে, দায় বসে
 *     LTR / LC     আমাদের হাত ছোঁয় না — ব্যাংক সরবরাহকারীকে দেয়
 *     লিজ          সম্পদ আসে, ডাউন পেমেন্ট যায়, বাকিটা দায়
 *     গ্যারান্টি     কিছুই যায় না — কেবল মার্জিন আটকে থাকে
 *
 * ⚠️ এই পাঁচটা উত্তর আলাদা বলেই ঘরগুলোও আলাদা নামে। ⛔ সাধারণ নামের
 * ঘরে (`a` · `b` · `c`) রাখলে ধরন বদলালে অর্থ বদলাত কিন্তু সংখ্যা থেকে
 * যেত — নকশার নমুনায় ঠিক সেটাই ঘটেছিল, আর গ্যারান্টির হিসাব দাঁড়িয়েছিল
 * ৩ লক্ষ ৬০ হাজার কোটি টাকা।
 */
class BankFacility extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /**
     * চলতি মূলধনের সীমা — টাকাটা ব্যাংক হিসাবে থাকে, এখানে নয়।
     *
     * ⛔ এটাই একমাত্র ধরন যার কোনো দায়ের খাত লাগে না।
     */
    public const CC = 'cc';

    /** নির্দিষ্ট অঙ্ক, নির্দিষ্ট কিস্তি, নির্দিষ্ট শেষ তারিখ */
    public const TERM = 'term';

    /** আমদানির অর্থায়ন — ব্যাংক সরাসরি সরবরাহকারীকে দেয় */
    public const LTR = 'ltr';

    /** কিস্তিতে সম্পদ — সম্পদ আজ, টাকা বছরের পর বছর */
    public const LEASE = 'lease';

    /** ⚠️ দায় নয় — যতক্ষণ না কেউ ভাঙায় */
    public const GUARANTEE = 'bg';

    /** @var list<string> */
    public const KINDS = [self::CC, self::TERM, self::LTR, self::LEASE, self::GUARANTEE];

    /**
     * জামানতের ধরন।
     *
     * ⓘ `LIEN` মানে আমাদের নিজের এফডিআর ব্যাংকে বন্ধক — আর ঐ জোড়াটা
     * [[Deposit]]-এ `pledged_to_loan_id` নামে **আগে থেকেই আছে**। ⭐ তাই
     * নতুন কিছু বানানো হয়নি।
     */
    public const UNSECURED = 'unsecured';

    public const HYPOTHECATION = 'hypothecation';

    public const MORTGAGE = 'mortgage';

    public const LIEN = 'lien';

    public const PERSONAL_GUARANTEE = 'personal';

    /** @var list<string> */
    public const SECURITIES = [
        self::HYPOTHECATION, self::MORTGAGE, self::LIEN, self::PERSONAL_GUARANTEE, self::UNSECURED,
    ];

    protected $table = 'fin_bank_facilities';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'kind', 'institution_id',
        'bank', 'branch_name', 'sanction_no', 'sanctioned_on',
        'limit_amount', 'interest_rate', 'term_months', 'renews_on',
        'stock_value', 'margin_percent', 'instalments', 'instalment_amount',
        'down_payment', 'charges',
        'security_type', 'security_value', 'guarantors', 'covenant', 'last_statement_on',
        'opening_drawn', 'opening_instalments_paid',
        'liability_account_id', 'money_account_id',
        'status', 'closed_on', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sanctioned_on' => 'date',
            'renews_on' => 'date',
            'last_statement_on' => 'date',
            'closed_on' => 'date',
            'limit_amount' => 'decimal:4',
            'opening_drawn' => 'decimal:4',
            'opening_instalments_paid' => 'integer',
            'interest_rate' => 'decimal:4',
            'stock_value' => 'decimal:4',
            'margin_percent' => 'decimal:2',
            'instalment_amount' => 'decimal:4',
            'down_payment' => 'decimal:4',
            'charges' => 'decimal:4',
            'security_value' => 'decimal:4',
        ];
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'liability_account_id');
    }

    /**
     * কোন ব্যাংক — তালিকা থেকে ([[Institution]])।
     *
     * ⓘ পুরনো `bank` ঘরটা থেকে যায়: মানুষ যা টাইপ করেছিলেন সেটাই
     * ঐতিহাসিক সত্য। নতুন সারিতে ঘরটা আর চাওয়া হয় না।
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** CC-র নিজের ব্যাংক হিসাব — বাকি চারটায় `null`। */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    /** @param  Builder<BankFacility>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', DocumentStatus::CONFIRMED);
    }

    /**
     * ⭐ ড্রয়িং পাওয়ার — CC-তে **স্টকই সীমা ঠিক করে**, মঞ্জুরি নয়।
     *
     * ── কেন এই হিসাবটা এখানে ─────────────────────────────────────────
     * ব্যাংক প্রতি মাসে স্টক স্টেটমেন্ট নেয়, আর তার উপর মার্জিন বাদ
     * দিয়ে যা থাকে ততটাই তোলা যায় — মঞ্জুরিকৃত সীমা যা-ই হোক।
     *
     * ⚠️ ⓘ দোকানদার প্রায়ই ভাবেন সীমাটাই তাঁর টাকা, আর মাসের শেষে
     * স্টক কমে গেলে হঠাৎ চেক ফেরত আসে — কারণ ড্রয়িং পাওয়ার নেমে গেছে।
     * ⛔ সংখ্যাটা কোথাও না দেখালে ঐ ধাক্কাটা প্রতিবারই অপ্রত্যাশিত।
     *
     * ⓘ CC ছাড়া বাকি ধরনে প্রশ্নটাই ওঠে না, তাই `null`।
     */
    public function drawingPower(): ?string
    {
        if ($this->kind !== self::CC || $this->stock_value === null) {
            return null;
        }

        $margin = (string) ($this->margin_percent ?? '0');
        $usable = bcmul((string) $this->stock_value, bcsub('1', bcdiv($margin, '100', 6), 6), 4);

        // ⓘ মঞ্জুরিকৃত সীমার বেশি কখনো নয় — যেটা ছোট সেটাই আজকের সীমা
        return bccomp($usable, (string) $this->limit_amount, 4) > 0
            ? (string) $this->limit_amount
            : $usable;
    }

    /**
     * এটা কি সত্যিকারের দায়?
     *
     * ⛔ গ্যারান্টি **নয়** — ওটা সম্ভাব্য দায়, স্থিতিপত্রের টীকায়।
     * ⛔ CC-ও নয় এই অর্থে — ওর দেনা ব্যাংক হিসাবের ঋণাত্মক ব্যালান্স,
     * আলাদা দায়ের খাত নয়।
     *
     * ⚠️ দুইটাকে ঋণ ধরে বসালে ব্যবসাটা নিজের চেয়ে বেশি ঋণগ্রস্ত দেখাত,
     * আর ব্যাংক পরের সুবিধা দিতে দ্বিধা করত।
     */
    public function isBalanceSheetDebt(): bool
    {
        return in_array($this->kind, [self::TERM, self::LTR, self::LEASE], true);
    }

    /**
     * নবায়নের সময় হয়ে এসেছে কি — ৩০ দিনের ভিতরে।
     *
     * ⓘ CC ও LTR বার্ষিক নবায়ন হয়। ⚠️ তারিখটা কেউ না দেখলে সুবিধাটা
     * নীরবে ফুরায়, আর টের পাওয়া যায় চেক ফেরত এলে।
     */
    public function renewalIsNear(): bool
    {
        return $this->renews_on !== null
            && $this->renews_on->isBefore(now()->addDays(30));
    }

    // ── Drillable — নিয়ম ১, "সংখ্যা থেকে কাগজে" ───────────────────────

    public static function drillSourceType(): string
    {
        return 'bank_facility';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no ?? $this->sanction_no ?? (string) $this->id;
    }

    public function drillLabel(): string
    {
        return __('finance::menu.bank_facility').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.bank_facility.show', ['bankFacility' => $this->id]];
    }
}
