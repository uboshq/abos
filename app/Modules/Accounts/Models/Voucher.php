<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
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

    /** @var list<string> */
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
        'trx_date', 'party_type', 'party_id', 'amount', 'narration',
        'instrument', 'instrument_no', 'instrument_date', 'money_account_id',
        'status', 'approved_by', 'approved_at',
        'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'instrument_date' => 'date',
            'amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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
