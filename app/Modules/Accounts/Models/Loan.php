<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা ঋণ — টার্ম লোন বা CC।
 *
 * দুইটা এক টেবিলে, কারণ "কে দিল, কোন খাতে, কত সুদ, কী জামানত" —
 * এই সবটা এক। আলাদা হয় কেবল ফেরতের গড়ন, আর সেটা kind দেখে ঠিক হয়।
 */
class Loan extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** নির্দিষ্ট টাকা, নির্দিষ্ট মেয়াদ, মাসিক কিস্তি। */
    public const TERM = 'term';

    /** একটা সীমা, তার ভেতরে যত খুশি তোলা-জমা। */
    public const CC = 'cc';

    /**
     * হাতধার — কাগজবিহীন ধার।
     *
     * কিস্তি নেই, সুদ সাধারণত শূন্য, আর ফেরতের তারিখটা একটা কথা:
     * "ঈদের আগে দিয়ে দেব"। তবু টাকাটা সত্যিকারের টাকা, আর খাতায় না
     * থাকলে বছর শেষে পুঁজির হিসাবটা ওই পরিমাণ ভুল হয়।
     */
    public const HAND = 'hand';

    /**
     * FD — নির্দিষ্ট টাকা ব্যাংকে রাখা, নির্দিষ্ট মেয়াদে, নির্দিষ্ট সুদে।
     *
     * উল্টো করে দেখলে এটা ব্যাংককে দেওয়া একটা টার্ম লোন, তাই ঘরগুলোও
     * একই। সুদটা কেবল উল্টো দিকে যায়: খরচ নয়, আয়।
     */
    public const FD = 'fd';

    /** DPS — একই জিনিস, কেবল টাকাটা মাসে মাসে জমে। */
    public const DPS = 'dps';

    /** ধারটা আমরা নিয়েছি — দায়। */
    public const TAKEN = 'taken';

    /** ধারটা আমরা দিয়েছি — পাওনা, দায় নয়। */
    public const GIVEN = 'given';

    protected $table = 'acc_loans';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'lender', 'account_no',
        'kind', 'direction', 'interest_method', 'sanctioned', 'interest_rate',
        'tenure_months', 'start_date', 'first_instalment_on', 'due_on',
        'matures_on', 'pledged_against_id',
        'principal_account_id', 'interest_account_id',
        'security', 'narration', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sanctioned' => 'decimal:4',
            'interest_rate' => 'decimal:4',
            'start_date' => 'date',
            'first_instalment_on' => 'date',
            'due_on' => 'date',
            'matures_on' => 'date',
        ];
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(LoanInstalment::class)->orderBy('no');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(LoanMovement::class)->orderBy('trx_date')->orderBy('id');
    }

    public function principalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'principal_account_id');
    }

    public function interestAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isTerm(): bool
    {
        return $this->kind === self::TERM;
    }

    public function isCc(): bool
    {
        return $this->kind === self::CC;
    }

    public function isHandLoan(): bool
    {
        return $this->kind === self::HAND;
    }

    /** FD বা DPS — ব্যাংকে রাখা টাকা। */
    public function isDeposit(): bool
    {
        return in_array($this->kind, [self::FD, self::DPS], true);
    }

    /** যে ঋণের বিপরীতে এই FD বাঁধা। */
    public function pledgedAgainst(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pledged_against_id');
    }

    /** এই ঋণের বিপরীতে যে জমাগুলো বাঁধা আছে। */
    public function pledges(): HasMany
    {
        return $this->hasMany(self::class, 'pledged_against_id');
    }

    /**
     * টাকাটা আছে, কিন্তু হাতে নেই।
     *
     * বাঁধা FD তালিকায় "আছে" দেখায়, অথচ ঋণ শোধ না হওয়া পর্যন্ত ওটা
     * ভাঙানো যায় না। এই পার্থক্যটা না বললে কেউ দরকারের দিনে ওই টাকার
     * উপর ভরসা করে সিদ্ধান্ত নেবেন — আর ওটাই সবচেয়ে দামি ভুল।
     *
     * ঋণটা শোধ হয়ে গেলে বাঁধনও খোলে: বন্ধক থাকে দায়ের জন্য, আর দায়
     * না থাকলে বন্ধকেরও কারণ থাকে না।
     */
    public function isLocked(): bool
    {
        if ($this->pledged_against_id === null) {
            return false;
        }

        return ! (bool) $this->pledgedAgainst?->isSettled();
    }

    /**
     * ধারটা আমরা দিয়েছি, নিইনি।
     *
     * এটাই ঠিক করে দেয় টাকাটা ব্যালেন্স শিটের কোন পাশে বসবে, আর
     * খতিয়ানে ডেবিট-ক্রেডিট কোন দিকে যাবে। ভুল হলে দুইবার ভুল হয়:
     * পাওনা দায় হয়ে বসে, আর মোট দায় ঠিক দ্বিগুণ পরিমাণ বেশি দেখায়।
     */
    public function isGiven(): bool
    {
        return $this->direction === self::GIVEN;
    }

    /**
     * ফেরতের কথা দেওয়া তারিখ পেরিয়ে গেছে, অথচ টাকাটা এখনো বাকি।
     *
     * হাতধারে এটাই একমাত্র সতর্কতা — কিস্তির সূচি নেই বলে অন্য কোনো
     * ভাবে দেরি ধরা পড়ে না। তারিখ না থাকলে দেরিও নেই: কেউ তারিখ
     * বলেননি মানে কথা ভাঙেননি।
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        if ($this->due_on === null) {
            return false;
        }

        if (bccomp($this->outstanding(), '0', 4) <= 0) {
            return false;
        }

        return $this->due_on->lessThan($asOf ?? Carbon::today());
    }

    /**
     * এখন কত বাকি — খতিয়ান থেকে, আলাদা কোনো কলাম থেকে নয়।
     *
     * ── কেন জমা রাখা হয় না ─────────────────────────────────────────
     * বকেয়া একটা কলামে রাখলে সেটা আর খতিয়ানের সাথে বাঁধা থাকত না।
     * একটা পরিশোধ ভুল করে দুইবার বসলে, বা কেউ ভাউচার দিয়ে সরাসরি
     * ঋণের খাতে হাত দিলে, দুইটা সংখ্যা আলাদা হয়ে যেত — আর কোনটা
     * সত্যি তা বলার উপায় থাকত না।
     *
     * দায় ক্রেডিট প্রকৃতির, তাই ডেবিট − ক্রেডিট ঋণাত্মক আসে; বকেয়া
     * হিসেবে পড়তে চিহ্নটা উল্টে দেওয়া হয়।
     *
     * ── দেওয়া ধারে চিহ্নটা উল্টো ────────────────────────────────────
     * হাতধার দেওয়া হলে খাতটা সম্পদ, দায় নয়: টাকা দেওয়ার সময় ওটা
     * ডেবিট হয়, তাই ডেবিট − ক্রেডিট এমনিতেই ধনাত্মক আসে। তখন আবার
     * উল্টে দিলে বকেয়া ঋণাত্মক দেখাত, আর "কে কত ফেরত দেবে" তালিকায়
     * প্রতিটা পাওনা মাইনাস চিহ্ন নিয়ে বসত।
     */
    public function outstanding(): string
    {
        /*
         * এই ঋণের সব ডকুমেন্ট — নড়াচড়া আর কিস্তি।
         *
         * ঋণ নিজে খতিয়ানে বসে না (LoanMovement-এ কারণটা লেখা), তাই
         * তার দায় খুঁজতে হয় তার ডকুমেন্টগুলোর মধ্য দিয়ে।
         */
        $entries = LedgerEntry::query()
            ->where('account_id', $this->principal_account_id)
            ->where(function ($q) {
                $q->where(function ($m) {
                    $m->where('source_type', LoanMovement::drillSourceType())
                        ->whereIn('source_id', LoanMovement::query()
                            ->where('loan_id', $this->id)
                            ->select('id'));
                })->orWhere(function ($i) {
                    $i->where('source_type', LoanInstalment::drillSourceType())
                        ->whereIn('source_id', LoanInstalment::query()
                            ->where('loan_id', $this->id)
                            ->select('id'));
                });
            })
            ->get();

        $balance = $entries->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );

        /*
         * `bcmul(..., 4)` মাপটাও বসিয়ে দেয়, তাই দুই দিকেই সেটাই ব্যবহার
         * করা হয় — কেবল গুণকটা আলাদা।
         *
         * দেওয়া ধারে আগে সরাসরি `$balance` ফেরত দেওয়া হচ্ছিল, আর তাতে
         * কোনো সারি না থাকলে '0' আসত, নেওয়ায় '0.0000'। একই পদ্ধতি দুই
         * রকম মাপের লেখা ফেরত দিলে তুলনা ও ছাপা দুই জায়গাতেই একদিন
         * ভাঙে।
         */
        return bcmul($balance, $this->isGiven() ? '1' : '-1', 4);
    }

    /** CC-তে সীমার আর কতটা খালি। */
    public function available(): string
    {
        return bcsub((string) $this->sanctioned, $this->outstanding(), 4);
    }

    public function isSettled(): bool
    {
        return bccomp($this->outstanding(), '0', 4) <= 0;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'loan';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->lender.' — '.$this->document_no;
    }

    public function drillRoute(): array
    {
        return ['accounts.loan.show', ['loan' => $this->id]];
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }
}
