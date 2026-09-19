<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা ভাউচার — পাঁচ ধরনের যেকোনো একটা।
 *
 * ধরনটা শুধু ঠিক করে কোন ফর্মে লেখা হবে ও কী ছাপা হবে। সংরক্ষণ ও
 * পোস্টিং সবার এক, কারণ সবগুলোই শেষমেশ ডেবিট-ক্রেডিটের কয়েকটা সারি।
 */
class Voucher extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    /** টাকা ঢুকল — গ্রাহকের আদায়, অন্য আয়। */
    public const RECEIPT = 'receipt';

    /** টাকা বেরোল — সরবরাহকারীকে পরিশোধ, ঋণ শোধ। */
    public const PAYMENT = 'payment';

    /** টাকা বেরোল খরচ হিসেবে — ভাড়া, বিদ্যুৎ, জ্বালানি। */
    public const EXPENSE = 'expense';

    /** যেকোনো খাত থেকে যেকোনো খাতে — সমন্বয়, অবচয়, সংশোধন। */
    public const JOURNAL = 'journal';

    /** টাকার খাত থেকে টাকার খাতে — ব্যাংকে জমা, ব্যাংক থেকে উত্তোলন। */
    public const CONTRA = 'contra';

    /**
     * সরাসরি বিক্রয়ের কাউন্টার থেকে আসা রসিদ — ১৯ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ অনুমোদনের কাজের নাম তখন `counter_deposit`, হাতে লেখা রসিদের
     * `receipt` নয় — কারণটা [[VoucherApproval::stopping()]]-এ।
     */
    public const ORIGIN_COUNTER = 'counter';

    /** @var list<string> */
    /**
     * টাকা কীভাবে হাতবদল হলো — পাঁচটা, আর কেবল পাঁচটা।
     *
     * ── ⛔ কেন এটা এখানে, ১৪ সেপ্টেম্বর ২০২৬ ──────────────────────────
     * তালিকাটা এতদিন **তিন জায়গায়** লেখা ছিল: [[VoucherRequest]]-এর
     * `Rule::in()`, ফর্মের `@foreach`, আর ভাষার ফাইল।
     *
     * ⚠️ আর তিনটা আলাদা হয়ে গিয়েছিল। নতুন কম্পোনেন্টে `online` লেখা
     * হয়েছিল যেখানে ব্যবস্থার নাম `transfer`, আর `card` বাদ পড়েছিল।
     * ⓘ ফল হত: ব্যাংক ট্রান্সফারের প্রতিটা রসিদ ভ্যালিডেশনে আটকাত, আর
     * কার্ডে নেওয়া পুরনো ভাউচার সম্পাদনা করলে মাধ্যমটা নীরবে বদলে যেত।
     *
     * ⭐ তাই সত্যটা এখন এক জায়গায়, আর বাকি সবাই এখান থেকে পড়ে।
     *
     * @var list<string>
     */
    public const INSTRUMENTS = ['cash', 'mfs', 'transfer', 'cheque', 'card'];

    public const TYPES = [self::RECEIPT, self::PAYMENT, self::EXPENSE, self::JOURNAL, self::CONTRA];

    /**
     * কোন ধরনের কোন নম্বর সিরিজ — module.php-তে ঘোষিত doc_types।
     *
     * @var array<string, string>
     */
    public const DOC_TYPES = [
        self::RECEIPT => 'RV',
        self::PAYMENT => 'PV',
        self::EXPENSE => 'EV',
        self::JOURNAL => 'JV',
        self::CONTRA => 'CV',
    ];

    /**
     * লেজারে কোন নামে বসবে — drill-down এই নামেই ফেরত আসে (নিয়ম ১)।
     *
     * @var array<string, string>
     */
    public const SOURCE_TYPES = [
        self::RECEIPT => 'receipt_voucher',
        self::PAYMENT => 'payment_voucher',
        self::EXPENSE => 'expense_voucher',
        self::JOURNAL => 'journal_voucher',
        self::CONTRA => 'contra_voucher',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'type', 'document_no',
        'trx_date', 'ref_date', 'party_type', 'party_id', 'amount', 'charge_amount', 'narration',
        'money_category_id', 'money_subcategory_id', 'against_type', 'against_id',
        'instrument', 'instrument_no', 'instrument_date', 'money_account_id',
        'from_bank', 'from_account_no',
        'status', 'approved_by', 'approved_at',
        'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_by',

        // ১৪ সেপ্টেম্বর — টাকা চলাচলের ব্লক
        'carried_by', 'moved_at', 'note_counts', 'wallet', 'wallet_medium',
        'counterparty_phone', 'charge_borne_by', 'transfer_mode_id',
        'from_branch', 'from_account_name', 'deposit_slip_no', 'lands_on',
        'reverse_on',

        // ১৫ সেপ্টেম্বর — খরচ ভাউচারের নিজের ঘর
        'cost_centre_id', 'expense_account_id', 'bill_no',
        'gross_amount', 'ait_amount', 'vds_amount',

        // ১৮ সেপ্টেম্বর — কাকে দেওয়া হলো: ধরন ও নাম
        'payee_type_id', 'payee_name',

        // ১৯ সেপ্টেম্বর — কোথা থেকে এল (কাউন্টারের ডিপোজিটের নিজের নিয়ম)
        'origin',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'ref_date' => 'date',
            'reverse_on' => 'date',
            'instrument_date' => 'date',
            'amount' => 'decimal:4',
            'charge_amount' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'ait_amount' => 'decimal:4',
            'vds_amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',

            /*
             * ⛔ ৫০০ এরর — "Array to string conversion", ১৮ সেপ্টেম্বর ২০২৬।
             *
             * ── যা হয়েছিল ────────────────────────────────────────────
             * মালিক পুঁজির খাতা থেকে "টাকা নিন → রসিদ ভাউচার" চেপে ফর্মটা
             * জমা দিয়েছেন, আর পর্দা ভেঙে গেছে। ⓘ কারণ নোটের ঘরগুলো
             * `note_counts[1000]`, `note_counts[500]` … আকারে আসে — অর্থাৎ
             * একটা **অ্যারে** — আর এখানে cast না থাকায় PDO ঐ অ্যারেটাকেই
             * সরাসরি bind করতে গিয়ে মারা গেছে।
             *
             * ── ⚠️ কেন কোনো পাহারায় ধরা পড়েনি ───────────────────────
             * কলামটা মাইগ্রেশনে আছে, `$fillable`-এ আছে, যাচাইয়ের নিয়মেও
             * (`'note_counts' => ['nullable','array']`) আছে। ⛔ তিনটা টেস্ট
             * ঐ তিনটাই মাপত — **কেউ একবারও অ্যারেটা ভরে সেভ করে দেখেনি**।
             * ⓘ ঠিক সেই পুরনো ফাঁদ: ঘর আছে, নাম আছে, কিছুই জোড়া লাগেনি,
             * আর কিছুই ভাঙে না — যতক্ষণ না একজন আসল মানুষ নোট গুনছেন।
             *
             * ⚠️ `array` — `json` নয়। দুইটা একই কাজ করে, কিন্তু প্রকল্পের
             * বাকি সব জায়গায় `array` লেখা, তাই এখানেও তাই।
             */
            'note_counts' => 'array',
        ];
    }

    /**
     * এই খরচটা কোন কোন চালানের ঘাড়ে — আর কার কতটা।
     *
     * ⓘ খালি থাকা বৈধ, আর তার মানে **পরোক্ষ খরচ**। মালিকের নিয়ম:
     * *"একটাও না বাছলে এটা পরোক্ষ; বাছলেই প্রত্যক্ষ।"*
     *
     * @return HasMany<VoucherBillShare, $this>
     */
    public function billShares(): HasMany
    {
        return $this->hasMany(VoucherBillShare::class);
    }

    /**
     * প্রত্যক্ষ খরচ কি না — চালান বাছা হয়েছে কি না, সেটাই একমাত্র প্রশ্ন।
     *
     * ⚠️ আলাদা কোনো "ধরন" ঘর রাখা হয়নি, ইচ্ছাকৃতভাবে। ⛔ ঘর থাকলে কেউ
     * "প্রত্যক্ষ" বেছে একটাও চালান না বাছতে পারতেন, আর দুইটা তথ্য
     * পরস্পরবিরোধী হয়ে বসে থাকত — তখন কোনটা সত্যি তা কেউ বলতে পারত না।
     */
    public function isDirectCost(): bool
    {
        return $this->billShares()->exists();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class);
    }

    /**
     * টাকাটা কোন শ্রেণির — আর সেই শ্রেণিই বলে দেয় কোন খাতে বসবে।
     *
     * ⓘ `null` হতে পারে, আর সেটা ফাঁক নয়: এই ঘরটা বসার আগের হাজার
     * হাজার ভাউচারের কোনো শ্রেণি নেই, আর জাবেদা ভাউচারে ওটা লাগেও না।
     *
     * @return BelongsTo<MoneyCategory, $this>
     */
    public function moneyCategory(): BelongsTo
    {
        return $this->belongsTo(MoneyCategory::class, 'money_category_id');
    }

    /**
     * উপ-শ্রেণি — একই তালিকার সারি, কেবল এক স্তর নিচে।
     *
     * @return BelongsTo<MoneyCategory, $this>
     */
    public function moneySubcategory(): BelongsTo
    {
        return $this->belongsTo(MoneyCategory::class, 'money_subcategory_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        return $query->whereIn('type', (array) $type);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('trx_date', [$from, $to]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('document_no', 'like', $like)
                ->orWhere('narration', 'like', $like)
                ->orWhere('instrument_no', 'like', $like);
        });
    }

    /** যেগুলো এখনো লেজারে বসেনি। */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::DRAFT);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::CONFIRMED);
    }

    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::CANCELLED;
    }

    /**
     * এখনো বদলানো যায় কি না।
     *
     * পোস্ট হওয়ার পর ভাউচার বদলানো যায় না। বদলাতে দিলে লেজারের
     * এন্ট্রিগুলো আর ভাউচারের সাথে মিলত না, আর ছাপা কাগজে যা আছে তার
     * সাথে পর্দায় যা আছে তার তফাত হত। সংশোধন করতে হয় বাতিল করে
     * নতুন ভাউচার দিয়ে — আর সেই পথটাই কাগজে-কলমে সঠিক পথ।
     */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function typeLabel(): string
    {
        return __('accounts::voucher.'.$this->type);
    }

    /** ডেবিট ও ক্রেডিটের যোগফল — সমান না হলে পোস্ট হয় না। */
    public function totals(): array
    {
        return [
            'debit' => $this->lines->reduce(fn ($c, $l) => bcadd((string) $c, (string) $l->debit, 4), '0'),
            'credit' => $this->lines->reduce(fn ($c, $l) => bcadd((string) $c, (string) $l->credit, 4), '0'),
        ];
    }

    public function isBalanced(): bool
    {
        $t = $this->totals();

        return bccomp($t['debit'], $t['credit'], 4) === 0
            && bccomp($t['debit'], '0', 4) > 0;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        // প্রতিটা ধরনের নিজের source_type আছে (SOURCE_TYPES), তাই এই
        // পদ্ধতিটা শুধু চুক্তি পূরণ করে। DrillResolver ধরন ধরে খোঁজে।
        return 'voucher';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->typeLabel().' — '.$this->document_no;
    }

    public function drillRoute(): array
    {
        return ['accounts.voucher.show', ['voucher' => $this->id]];
    }
}
