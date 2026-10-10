<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\KeepsRevisions;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\RepostsAfterRevision;
use App\Core\Contracts\ShowsItselfForSigning;
use App\Core\Services\PartyRegistry;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
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
class Voucher extends Model implements Drillable, RepostsAfterRevision, ShowsItselfForSigning
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use KeepsRevisions;
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

    /** ⓘ নগদ গোনার সমন্বয় — ব্যবস্থার কাগজ, টিলের নিয়ম পেরোয় (অডিট হিসাব ⚠️১৪, ৬ অক্টোবর ২০২৬; [[CashCountService]]) */
    public const ORIGIN_CASH_COUNT = 'cash_count';

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

    /**
     * ⭐ মাধ্যমের ঘরে যা-ই থাক, পর্দায় একটা শব্দ — কাঁচা চাবি কখনো নয়।
     *
     * ── ⛔ কলামটায় দুইটা অর্থ জমা আছে, আর সেটা ইচ্ছাকৃত ─────────
     * ⓘ হাতে লেখা ভাউচারে কোডবদ্ধ তালিকা ([[self::INSTRUMENTS]], দরজায়
     * `Rule::in`), আর **কাউন্টারের** ভাউচারে পেমেন্ট-পদ্ধতির **কোড**
     * (`CASH`, `BKASH`)। লাইভে তাই ঘরটায় `accounts::instrument.CASH` ছাপা হত।
     *
     * ── ⚠️ আর কোডটার অর্থ হিসাব মডিউল জানে না, আর জানাও চলবে না ─
     * ⛔ প্রথমে `PaymentMethod` দেখে কোড → ধরন খোঁজেছিলাম, আর
     * [[BoundariesTest::test_no_module_reaches_into_one_it_did_not_declare]]
     * সেটা ধরেছে। ⓘ Accounts-এর `depends_on` ইচ্ছাকৃতভাবে ফাঁকা — বাকি
     * সবাই এর উপর দাঁড়ায়, তাই ঘোষণা দিয়ে পার পাওয়া হত স্তরগুলো
     * উল্টে দেওয়া।
     *
     * ── ⭐ আর উত্তরটা হিসাবের নিজের কাছেই ছিল ────────────────
     * ⓘ টাকাটা কোন খাতে গেল, ওটার ধরন ([[Account::money_kind]]) হিসাবের
     * নিজস্ব। `BKASH` লেখা রসিদের টাকা একটা MFS খাতে বসে, তাই
     * শব্দটা বের করতে অন্য মডিউলের দরকার নেই।
     *
     * ⚠️ এই নিয়মটা আগে তালিকার পর্দায় নিজের একটা `match()`-এ লেখা
     * হত, আর এখানে আরেকটা হলে দুইটা তালিকা একদিন আলাদা হয়ে যেত।
     * ⭐ তাই তালিকার ওই `match()` মুছে এখানে একটাই রাখা হলো।
     *
     * ── ⓘ তিন ধাপ, আর শেষ ধাপে কখনো চাবি নয় ────────────────
     * কোডবদ্ধ মান হলে তার শব্দ · নাহলে টাকার খাতের ধরন · তাও না
     * পেলে যা লেখা আছে তাই — তবে কখনো `accounts::instrument.` নয়।
     */
    public function wayInWords(): ?string
    {
        $mode = trim((string) $this->instrument);

        if ($mode !== '') {
            $key = 'accounts::instrument.'.$mode;
            $words = __($key);

            if (is_string($words) && $words !== $key) {
                return $words;
            }
        }

        $word = match ($this->wayKind()) {
            Account::CASH => __('accounts::instrument.cash'),
            Account::MFS => __('accounts::instrument.mfs'),
            Account::BANK => __('accounts::instrument.transfer'),
            default => null,
        };

        if (is_string($word) && $word !== '') {
            return $word;
        }

        /* ⓘ শেষ আশ্রয় — চেনা গেল না, তবু মানুষের পড়ার মতো কিছু */
        return $mode === '' ? null : $mode;
    }

    /**
     * টাকাটা কোন ধরনের খাতে বসল — সারি থেকে, কোনো কোয়ারি ছাড়া।
     *
     * ⚠️ সম্পর্ক তোলা না থাকলে চুপ করে সরে যাওয়া হয়, আর সেটা
     * ইচ্ছাকৃত: এখানে একটা `find()` বসালে পঞ্চাশ সারির তালিকায়
     * পঞ্চাশটা কোয়ারি হত, আর স্থানীয়ভাবে `preventLazyLoading`
     * পাতাটাই ভাঙত। ⓘ যে পর্দা এই শব্দটা চায় সে সারিগুলো আগেই
     * তোলে ([[self::signingSheet()]]-এ `loadMissing`, তালিকায় `with`)।
     */
    private function wayKind(): ?string
    {
        if (! $this->relationLoaded('lines')) {
            return null;
        }

        foreach ($this->lines as $line) {
            if (! $line->relationLoaded('account')) {
                return null;
            }

            $account = $line->account;

            if ($account instanceof Account && $account->isMoney()) {
                return $account->money_kind;
            }
        }

        return null;
    }

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
        // ⭐ মাসশেষের সমন্বয় — ভাউচারের পরিকল্পনা ৩ঘ (৭ অক্টোবর ২০২৬); কেবল জাবেদায় ([[VoucherService::create()]])
        'is_adjusting',
        // ⭐ নিজে-উল্টো জাবেদা কোন আসলের — একবারই ([[AdjustingReversals]])
        'reversal_of_id',

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
            'is_adjusting' => 'boolean',
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

    /**
     * সইকারীর পাতায় ভাউচার — ২৮ সেপ্টেম্বর ২০২৬ ([[ShowsItselfForSigning]])।
     *
     * ⓘ ভাউচারের নিজের পাতার "বিস্তারিত" অংশ আর সারির টেবিল — একই ঘর, একই
     * ক্রমে, যাতে সইকারী যা দেখে সই দেন আর পরে যা খোলেন তা এক। ⚠️ লেনদেন
     * নম্বর আর টাকার খাত আগে: ব্যাংক বা বিকাশের টাকায় সইকারীর প্রথম প্রশ্ন
     * "টাকাটা সত্যিই এসেছে তো" — আর উত্তর মেলাতে ঠিক এই দুইটাই লাগে।
     */
    public function signingSheet(): array
    {
        $this->loadMissing(['lines.account', 'branch', 'creator']);

        $parties = app(PartyRegistry::class)->labelsOf(
            $this->lines
                ->map(fn (VoucherLine $l) => [(string) $l->party_type, (int) $l->party_id])
                ->push([(string) $this->party_type, (int) $this->party_id]),
        );

        /*
         * ⚠️ `money_account_id` বসে কেবল পোস্টের মুহূর্তে
         * ([[VoucherService::assertBankReferenceIsFree()]]) — অথচ সইকারী দেখেন
         * পোস্টের **আগে**। তাই না থাকলে সারি থেকে টাকার খাতটা, ভাউচারের নিজের
         * পাতা যেভাবে খোঁজে; নাহলে "কোথায়" ঘরটা সই চাওয়া প্রতিটা কাগজে খালি থাকত
         * (TheSignerSeesThePaperBeforeSigningTest ধরেছে)।
         */
        $money = $this->money_account_id === null
            ? $this->lines->map(fn (VoucherLine $l) => $l->account)
                ->first(fn (?Account $a) => $a !== null && ($a->isBank() || $a->isMfs() || $a->isCash()))
            : Account::query()->find($this->money_account_id);

        /*
         * ⓘ কাউন্টার এই ঘরে পদ্ধতির **কোড** বসায় (`BKASH`), হাতে লেখা ভাউচার
         * কোডবদ্ধ মান (`cash`) — সইয়ের কাগজে দুইটাই শব্দ হয়ে ওঠা দরকার।
         *
         * ⚠️ আগে অনুবাদ না মিললে কোডটাই ছাপা হত, তাই সইকারী `BKASH` পড়তেন।
         * ⭐ এখন [[self::wayInWords()]] টাকার খাতের ধরন ধরেও খোঁজে।
         */
        $way = $this->wayInWords();

        $facts = array_filter([
            __('accounts::field.date') => DateFormat::format($this->trx_date),
            __('approval::field.document') => $this->originLabel() ?? $this->typeLabel(),
            __('approval::field.party') => $parties[$this->party_type.':'.$this->party_id] ?? null,
            __('accounts::field.instrument') => $way,
            __('accounts::field.instrument_no') => $this->instrument_no,
            __('accounts::field.instrument_date') => DateFormat::format($this->instrument_date),
            __('approval::field.where_money') => $money?->label(),
            __('core.company.branch') => $this->branch?->name(),
            __('core.table.narration') => $this->narration,
            __('core.print.prepared_by') => $this->creator?->name,
        ], fn ($v) => filled($v));

        $totals = $this->totals();

        return [
            'facts' => array_map(
                fn ($label, $value) => ['label' => (string) $label, 'value' => (string) $value],
                array_keys($facts),
                $facts,
            ),
            'columns' => [
                ['key' => 'account', 'label' => __('core.print.account')],
                ['key' => 'party', 'label' => __('approval::field.party')],
                ['key' => 'narration', 'label' => __('core.table.narration')],
                ['key' => 'debit', 'label' => __('core.table.debit'), 'numeric' => true],
                ['key' => 'credit', 'label' => __('core.table.credit'), 'numeric' => true],
            ],
            'rows' => $this->lines->map(fn (VoucherLine $l) => [
                'account' => $l->account?->label(),
                'party' => $parties[$l->party_type.':'.$l->party_id] ?? null,
                'narration' => $l->narration,
                'debit' => bccomp((string) $l->debit, '0', 4) > 0 ? Money::format($l->debit) : null,
                'credit' => bccomp((string) $l->credit, '0', 4) > 0 ? Money::format($l->credit) : null,
            ])->values()->all(),
            'totals' => [
                'debit' => Money::format($totals['debit']),
                'credit' => Money::format($totals['credit']),
            ],
            'party' => $this->party_type !== null && $this->party_id !== null
                ? ['type' => (string) $this->party_type, 'id' => (int) $this->party_id]
                : null,
        ];
    }

    /**
     * সইয়ের ছাপের বাইরে — কেবল ব্যাংক বা MFS-এর লেনদেন নম্বর ([[DocumentFingerprint]])।
     *
     * ── ⭐ কেন — ২৭ সেপ্টেম্বর ২০২৬, লাইভে TCL-এর RCV-0001 ─────────────
     * নম্বরটা টাকা নড়ার **প্রমাণ**, সইকারী যে শর্তে সম্মতি দেন তা নয়
     * (অঙ্ক, খাত, পক্ষ, তারিখ, মাধ্যম — ওগুলো ছাপেই থাকে)। ⓘ আর নম্বরটা
     * জন্মায় টাকা নড়ার পরে, তাই চাওয়া হয় পোস্টের মুহূর্তে ([[show.blade.php]])।
     * ⛔ ছাপে থাকলে সইয়ের পরে নম্বর বসাতেই ছাপ বদলাত, সই বাতিল হত, আর
     * প্রতিটা ব্যাংক রসিদে দুইবার সই লাগত।
     *
     * ⚠️ মালিকের ২৪ সেপ্টেম্বরের নিয়মের ("যেকোনো ঘর বদলালে সই বাতিল")
     * একটা সংকীর্ণ ব্যতিক্রম — সমন্বয়কারীর সিদ্ধান্ত। বাকি তিন শর্ত:
     * পোস্টের পরে নম্বর আর বদলায় না, বসানোটা অডিটে ওঠে, আর অর্থহীন নম্বর
     * আটকায় ([[VoucherService::assertBankReferenceIsFree()]])।
     *
     * @return list<string>
     */
    public function fingerprintIgnores(): array
    {
        /*
         * ⓘ সমন্বয়ের দাগ (৩ঘ) — দাগ না থাকলে ছাপের বাইরে, থাকলে ভিতরে। ⛔ নতুন ঘরটা শূন্য-মানেও ছাপে ঢুকলে মাইগ্রেশনের
         * পরে সইয়ের অপেক্ষার প্রতিটা ভাউচারের ছাপ বদলাত আর আজকের সই বাতিল হত; দাগ বসালে বা তুললে কাগজ বদলায়, সই চায়।
         */
        return [
            'instrument_no',
            ...($this->is_adjusting ? [] : ['is_adjusting']),
            ...($this->reversal_of_id === null ? ['reversal_of_id'] : []),
        ];
    }

    /**
     * ⭐ ছাপে কোন সারিগুলো — সবসময় এগুলো, হাতে যা তোলা আছে তা নয় (২৮ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ খাত আর অঙ্ক থাকে সারিতেই (`account_id`, `debit`, `credit`), তাই
     * `lines` যথেষ্ট — খাত বদলালেও ছাপ বদলায়। ⛔ আগে ছাপ নির্ভর করত ডাকার
     * জায়গায় কী তোলা ছিল তার উপর, আর সই হওয়া খরচ "পোস্ট"-এ আবার সই চাইত
     * ([[DocumentFingerprint::asStored()]])।
     *
     * @return list<string>
     */
    public function fingerprintRelations(): array
    {
        return ['lines'];
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

    /**
     * ⭐ তালিকার মোটে যে অঙ্ক যায় — কেবল পাকা ভাউচারের; বাতিল আর খসড়া শূন্য (পাতা-ঝাড়ু ধাপ ০, ১০ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে রসিদের তালিকার মোট ৫,১২,৩৩৯-এর ৫,০৫,০০০ ছিল বাতিল রসিদের — খাতায় যা নেই, মোটে তা যোগ হত। ⓘ সারিগুলো আগের মতোই
     * দেখায়; কেবল মোট বদলায়। পাতার মোট এটা নেয়, আর সব পাতার সর্বমোট [[countedAmountSql()]]।
     */
    public function countedAmount(): string
    {
        return in_array($this->status, \App\Core\Support\DocumentStatus::POSTED, true) ? (string) $this->amount : '0';
    }

    /** ⓘ একই নিয়ম সর্বমোটের SQL-এ ([[GrandTotals]]); `$alias` — উপ-কোয়েরির নাম */
    public static function countedAmountSql(string $alias = 't'): string
    {
        $posted = implode(', ', array_map(fn (string $s) => "'".$s."'", \App\Core\Support\DocumentStatus::POSTED));

        return "CASE WHEN {$alias}.status IN ({$posted}) THEN {$alias}.amount ELSE 0 END";
    }

    public function typeLabel(): string
    {
        // ⭐ "সমন্বয় জাবেদা" — পাতা, ছাপা, সারাংশ আর সইয়ের পাতা একই নাম পায় (ভাউচারের পরিকল্পনা ৩ঘ)
        return __('accounts::voucher.'.($this->type === self::JOURNAL && $this->is_adjusting ? 'adjusting_journal' : $this->type));
    }

    /**
     * ভাউচারটার নিজের নাম — কোথা থেকে এসেছে সেটা ধরে।
     *
     * ── ⭐ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────
     * *"ei আদায় ভাউচার er nam hobe sales added deposit"* — বিক্রয়ের
     * পর্দা থেকে নেওয়া জমার রসিদটা যেন খুলেই বলে দেয় সে কার।
     *
     * ── ⚠️ কেন `origin` একা যথেষ্ট নয় ───────────────────────────────
     * ⓘ `counter` লেখাটা **দুই জায়গায়** বসে: বিক্রয়ে জমা নেওয়ার সময়
     * আর ক্রয়ে পরিশোধ করার সময়। ⛔ কেবল origin ধরে নাম দিলে ক্রয়ের
     * পরিশোধের কাগজেও "বিক্রয়ে যোগ করা জমা" লেখা উঠত — এক শব্দের ভুল,
     * আর টাকার কাগজে ভুল দিক দেখানোর চেয়ে খারাপ কিছু নেই।
     *
     * ⭐ তাই জোড়াটা মাপা হয়: **ধরন আর উৎস একসাথে**।
     *
     * ⓘ অন্য সব ভাউচারে `null` ফেরে, আর পর্দা তখন আগের মতোই
     * `typeLabel()` দেখায় — কোনো পাতায় কিছু বদলায় না।
     */
    public function originLabel(): ?string
    {
        if ($this->origin !== self::ORIGIN_COUNTER) {
            return null;
        }

        return match ($this->type) {
            self::RECEIPT => __('accounts::voucher.origin_sales_deposit'),
            self::PAYMENT => __('accounts::voucher.origin_purchase_payment'),
            default => null,
        };
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

    // ── RepostsAfterRevision — পোস্ট হওয়া ভাউচারের সংশোধন, ৩ অক্টোবর ২০২৬ ─────
    //
    // ⓘ মালিক: মাস বন্ধের আগে সুপার অ্যাডমিন যেকোনো পোস্ট হওয়া কাগজ সংশোধন করেন — নম্বর একই, খাতা
    // উল্টে আবার বসে, আগে-পরে দুইটাই থাকে ([[App\Core\Services\RevisionKeeper]])। পথটা
    // [[VoucherService::editPosted()]]; বাকি সব ডিফল্ট [[KeepsRevisions]]-এ।

    /** @return list<array{0: string, 1: int}> খাতায় ভাউচার নিজের ধরনের নামে বসে */
    public function revisionLedgerSources(): array
    {
        return [[self::SOURCE_TYPES[$this->type], (int) $this->id]];
    }

    /** ⓘ ছাপার খাতায় ভাউচারের নাম ([[PaperTrail::DOCUMENT_ROUTES]]) — আগে ছাপা হয়েছিল কি না */
    public function revisionPaperType(): ?string
    {
        return 'accounts_voucher';
    }

    /** ⭐ আবার বসানো ভাউচারের নিজের পোস্টিংয়ের পথে — খাত, ভারসাম্য, টিল, টাকা আছে কি না, সব আবার খাটে */
    public function repostAfterRevision(): void
    {
        app(\App\Modules\Accounts\Services\VoucherService::class)->repostAfterRevision($this);
    }

    /** @return list<string> সারির ক্রম কেবল দেখানোর — ছবিতে বসালে একটা সারি যোগ হলেই নিচের সব "বদলেছে" দেখাত */
    protected function revisionLineIgnores(): array
    {
        return ['sort_order'];
    }

    /** @return list<string> */
    protected function revisionLineWith(): array
    {
        return ['account'];
    }

    /** @return array<string, scalar|null> খাতের নাম — কেবল id দেখে কেউ বলতে পারত না কী বদলেছে */
    protected function revisionLineExtras(Model $line): array
    {
        return ['account' => $line instanceof VoucherLine ? $line->account?->label() : null];
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
