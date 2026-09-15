<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\MasterData\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * একটা জমা — ব্যাংক আমানত, সঞ্চয়পত্র বা বন্ড।
 *
 * ── কেন এক টেবিল, তিন মেনু ───────────────────────────────────────────
 * তিনটার ঘর একই: কোথায় রাখা, কত, কত হারে, কবে খোলা, কবে মেয়াদ শেষ।
 * তিনটা টেবিল করলে একই নয়টা কলাম তিনবার লিখতে হত, আর "মোট কত টাকা
 * সরিয়ে রাখা আছে" প্রশ্নের উত্তর দিতে তিনটা জোড়া লাগত।
 *
 * মেনুতে তিনটা সারি, কারণ মানুষ ওভাবেই ভাবেন — কেউ "জমা" খোঁজেন না,
 * খোঁজেন "সঞ্চয়পত্র"। ছাঁকনিটা `issuer` কলামে।
 *
 * ── সঞ্চয়পত্র কোম্পানির সম্পদ, নাকি মালিকের উত্তোলন? ─────────────────
 * **দুইটাই — আর সারিটাই বলে দেয় কোনটা।**
 *
 * সঞ্চয়পত্র আইনত ব্যক্তির জিনিস; ফার্ম বা কোম্পানি কিনতে পারে না।
 * তাই `held_by` ঘরটা অপরিহার্য, আর দাখিলা ওটাই ঠিক করে:
 *
 *   `business` — ব্যবসার নামে (ব্যাংক আমানত, প্রাইজ বন্ড)।
 *                Dr জমা (সম্পদ) · Cr নগদ/ব্যাংক।
 *                স্থিতিপত্রে সম্পদ হিসেবে বসে।
 *
 *   `owner`    — মালিকের নামে (সঞ্চয়পত্র, ডলার বন্ড)।
 *                Dr উত্তোলন (৩২০০) · Cr নগদ/ব্যাংক।
 *                **ব্যবসার সম্পদ নয়** — কেবল জানার জন্য রাখা।
 *
 * ── কেন দুইটাই দরকার, একটা বেছে নেওয়া নয় ───────────────────────────
 * শুধু সম্পদ ধরলে স্থিতিপত্রে এমন কিছু বসত যা ব্যবসার নয়, আর অডিটে
 * ধরা পড়ত। শুধু উত্তোলন ধরলে মালিক নিজের সঞ্চয়পত্রগুলো আর কোথাও
 * দেখতে পেতেন না — অথচ ওটাই তাঁর সবচেয়ে বড় সঞ্চয়, আর ওটা দেখার
 * জন্যই তিনি এটা চেয়েছেন।
 *
 * সারিটা থাকে দুই ক্ষেত্রেই; কেবল টাকার দাখিলাটা আলাদা।
 */
class Deposit extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /** ব্যবসার নামে — স্থিতিপত্রে সম্পদ */
    public const BUSINESS = 'business';

    /** মালিকের নামে — ব্যবসার টাকা গেলে সেটা উত্তোলন */
    public const OWNER = 'owner';

    /*
     * মেয়াদ শেষে কী হবে — তিনটাই ব্যাংকের ফর্মে ছাপা থাকে।
     *
     * ⛔ ঘরটা ছিল কলামে, কিন্তু কোনো পর্দায় নয় — ১৫ সেপ্টেম্বর ২০২৬-এ
     * মালিক লোকালে খুলে ধরিয়ে দিয়েছেন। ⚠️ ডিফল্ট `renew_with_profit`,
     * কারণ FDR না বললে ব্যাংক ওটাই করে; কিন্তু ⭐ **ধরে নেওয়া আর
     * লেখা এক নয়** — মেয়াদ শেষে টাকাটা কোথায় যাবে সেটা আগে থেকে
     * লেখা না থাকলে ঐ দিনটায় কেউ জানে না কী করতে হবে।
     */
    public const RENEW_WITH_PROFIT = 'renew_with_profit';

    public const RENEW_PRINCIPAL_ONLY = 'renew_principal_only';

    public const ENCASH = 'encash';

    /** @var list<string> */
    public const ON_MATURITY = [
        self::RENEW_WITH_PROFIT, self::RENEW_PRINCIPAL_ONLY, self::ENCASH,
    ];

    public const ACTIVE = 'active';

    /** মেয়াদ শেষ বা ভাঙা হয়েছে — একটা ব্যবসায়িক ঘটনা */
    public const CLOSED = 'closed';

    /**
     * ভুল করে বসানো হয়েছিল — উল্টো দাখিলা, সারিটা থেকে যায়।
     *
     * ── কেন এটা `CLOSED` থেকে আলাদা ─────────────────────────────────
     * ভাঙা মানে ব্যাংক টাকা ফেরত দিয়েছে; বাতিল মানে জমাটা কোনোদিন
     * ছিলই না। এক অবস্থায় মেলালে "কত টাকা ফেরত এসেছে" রিপোর্টে এমন
     * টাকা যোগ হত যা কেউ কোনোদিন পায়নি।
     */
    public const CANCELLED = 'cancelled';

    protected $table = 'fin_deposits';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'kind_id', 'institution',
        'branch_name', 'reference_no', 'held_by', 'person_id', 'principal',
        'profit_rate', 'tax_rate', 'return_word', 'opened_on', 'matures_on', 'on_maturity',
        'instalment_amount', 'instalment_day', 'payout_account_id', 'account_id',
        'funded_from_account_id', 'pledged_to_loan_id', 'status', 'closed_on',
        'note', 'cancel_reason', 'cancelled_at', 'cancelled_by', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'principal' => 'decimal:4',
            'profit_rate' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'instalment_amount' => 'decimal:4',
            'opened_on' => 'date',
            'matures_on' => 'date',
            'closed_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * কার নামে রাখা — নাম নয়, তালিকার সারি।
     *
     * ── কেন এটা `held_by`-র বদলে নয়, তার পাশে ────────────────────────
     * `held_by` বলে **ব্যবসার না মালিকের** (দাখিলা ওটাই ঠিক করে), আর
     * এই ঘরটা বলে **কোন মানুষ**। দুইটা আলাদা প্রশ্ন: ব্যবসার নামে রাখা
     * আমানতের কোনো ব্যক্তি নেই, তাই এটা `null` থাকতে পারে।
     *
     * ⓘ আগে ঘরটা ছিল `holder_name`, মুক্ত লেখা। আমানত বছরের পর বছর
     * থাকে, তাই ওখানে বানানের ভিন্নতা সবচেয়ে বেশি সময় ধরে জমত — আর
     * "মালিকের নামে মোট কত আমানত" প্রশ্নের উত্তর টুকরো হয়ে যেত।
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(DepositKind::class, 'kind_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DepositMovement::class);
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payout_account_id');
    }

    /** @param  Builder<Deposit>  $query */
    public function scopeIssuedBy(Builder $query, string $issuer): void
    {
        $query->whereHas('kind', fn (Builder $q) => $q->where('issuer', $issuer));
    }

    /** @param  Builder<Deposit>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    /**
     * বাতিল হওয়া জমা কোনো যোগফলে গোনা হয় না।
     *
     * সারিটা তালিকায় থাকে — অডিটে প্রশ্ন উঠলে উত্তরটা লাগে — কিন্তু
     * "কত টাকা সরিয়ে রাখা আছে" সংখ্যায় ওটা শূন্য, কারণ ওই টাকাটা
     * কোনোদিন কোথাও যায়নি।
     */
    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * এটা কি ব্যবসার সম্পদ, নাকি মালিকের ব্যক্তিগত।
     *
     * পর্দায় দুইটার রং আলাদা, কারণ মোট যোগ করার সময় দুইটা একসাথে
     * করা যায় না — একটা স্থিতিপত্রে আছে, অন্যটা নেই।
     */
    public function isBusinessAsset(): bool
    {
        return $this->held_by === self::BUSINESS;
    }

    /**
     * মেয়াদ শেষ হতে আর কত দিন — শেষ হয়ে গেলে ঋণাত্মক।
     *
     * ── কেন এই সংখ্যাটা ─────────────────────────────────────────────
     * মেয়াদোত্তীর্ণ FD ব্যাংকে পড়ে থাকে, আর সাধারণ সঞ্চয়ী হারে সুদ
     * পায় — অর্থাৎ প্রতিদিন টাকা হারায়। কেউ তারিখ মনে রাখে না; পর্দা
     * রাখে।
     */
    public function daysToMaturity(): ?int
    {
        return $this->matures_on === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->matures_on, false);
    }

    /**
     * যে ঋণের বিপরীতে এই জমাটা বন্ধক।
     *
     * ---- কেন এটা এখানে এল, ৩০ আগস্ট ২০২৬ ----
     * আগে FD ছিল `acc_loans`-এর একটা ধরন, আর বন্ধনটা ছিল ঋণ থেকে
     * ঋণে (`pledged_against_id`)। জমা নিজের পর্দা পাওয়ার পর ব্যাংকে
     * টাকা রাখার দুইটা দরজা হয়ে গেল, আর মালিক সেটাই বন্ধ করতে বললেন।
     *
     * বন্ধনটা তাই সাথে আসছে -- নাহলে একটা দরজা বন্ধ করতে গিয়ে "আমার
     * FD-টা ঋণের বিপরীতে বাঁধা" কথাটা বলার জায়গাই থাকত না, আর ওটা
     * পরিষ্কার করা নয়, ক্ষমতা হারানো।
     */
    public function pledgedToLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'pledged_to_loan_id');
    }

    /**
     * টাকাটা আছে, কিন্তু হাতে নেই।
     *
     * ---- কেন এটা আলাদা করে বলা লাগে ----
     * বাঁধা জমা তালিকায় "আছে" দেখায়, অথচ ঋণ শোধ না হওয়া পর্যন্ত ওটা
     * ভাঙানো যায় না। পার্থক্যটা না বললে কেউ দরকারের দিনে ওই টাকার
     * উপর ভরসা করে সিদ্ধান্ত নেবেন -- আর ওটাই সবচেয়ে দামি ভুল।
     *
     * ঋণটা শোধ হয়ে গেলে বাঁধনও খোলে: বন্ধক থাকে দায়ের জন্য, আর দায়
     * না থাকলে বন্ধকেরও কারণ থাকে না।
     */
    public function isLocked(): bool
    {
        if ($this->pledged_to_loan_id === null) {
            return false;
        }

        $loan = $this->pledgedToLoan;

        if ($loan === null) {
            return false;
        }

        /*
         * ঘোরানো সীমায় আজকের ব্যালেন্স উত্তর নয়।
         *
         * সিসিতে টাকা রোজ ওঠে-নামে, আর শূন্যেও নামে -- ওটাই তার
         * স্বভাব। ব্যাংক প্রতিবার FD ফেরত দেয় না; জামানত থাকে সীমাটার
         * বিপরীতে ([[Loan::isRevolving()]])।
         */
        if ($loan->isRevolving()) {
            return true;
        }

        return ! $loan->isSettled();
    }

    /*
     * ── Drillable — ⭐ কেন খাতাটা নিজেই, তার নড়াচড়া নয় ───────────────
     *
     * ⛔ ১৫ সেপ্টেম্বর ২০২৬ পর্যন্ত কেবল নড়াচড়ার সারিগুলো Drillable ছিল
     * (`deposit_movement` ধরনের), খাতাটা নয়।
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
        return 'deposit';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no ?? $this->reference_no ?? (string) $this->id;
    }

    public function drillLabel(): string
    {
        return __('finance::menu.deposits_all').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        /*
         * ⚠️ `issuer` পথের অংশ, আর সেটা আসে জমার ধরন থেকে
         * ([[DepositKind::ISSUERS]])। ⓘ ধরনটা কোনো কারণে না থাকলে
         * `bank` — কারণ রুটটা `whereIn(ISSUERS)` দিয়ে বাঁধা, আর
         * অচেনা শব্দ দিলে লিংকটা ৪০৪ হত।
         */
        return ['finance.deposit.show', [
            'issuer' => $this->kind?->issuer ?? 'bank',
            'deposit' => $this->id,
        ]];
    }
}
