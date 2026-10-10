<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ListedInViewedBranch;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Support\OpensOnlyInReach;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * ⭐ ভাড়াটের চুক্তি — আমরা যখন জায়গা ভাড়া দিই (মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬; [[TenancyService]])।
 *
 * ⓘ ভাড়ার চুক্তির ([[RentalContract]], আমরা ভাড়াটে) উল্টো দিক, তবু আলাদা জিনিস: ওখানের জামানত, অগ্রিম মাস, কর আর ফেরতের
 * প্রতিটা পথ "আমরা দিই" ধরে লেখা, উল্টো খাত গুঁজলে চালু হিসাব ভাঙত।
 *
 * ── এখানে কোনো "কত বাকি" কলাম নেই ────────────────────────────────
 * বকেয়া = খাতায় বসা মাসের দাবি − খাতায় বসা আদায় ও জামানত থেকে কাটা; জামানত = নেওয়া − কাটা − ফেরত। ⓘ কেবল খাতায় বসা ভাউচার
 * গোনে, তাই সইয়ের অপেক্ষার বা বাতিল টাকা কোথাও ঢোকে না, আর সব ভাড়াটের যোগ ১১২৫ আর ২১৫৫-এর জেরের সমান।
 */
class Tenancy extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ListedInViewedBranch;
    use OpensOnlyInReach;
    use SoftDeletes;

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    /** ⛔ খোলা হয়েছে, জামানত নেওয়ার সই এখনো পড়েনি — চলে না, মাসের দাবিও বসে না */
    public const AWAITING = 'awaiting';

    /** @var list<string> */
    public const STATES = [self::ACTIVE, self::AWAITING, self::CLOSED];

    /** ⓘ ভাড়াটে কে হতে পারেন — ব্যক্তি বা গ্রাহক (সমন্বয়কের শর্ত); পক্ষের খাতা এই দুই ধরন চেনে */
    public const PARTY_TYPES = ['person', 'customer'];

    protected $table = 'fin_tenancies';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'tenant', 'tenant_phone', 'party_type', 'party_id', 'premises',
        'income_account_id', 'deposit_amount', 'monthly_rent', 'rent_day', 'starts_on', 'term_months', 'ends_on',
        'status', 'closed_on', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'deposit_amount' => 'decimal:4',
            'monthly_rent' => 'decimal:4',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_on' => 'date',
            'term_months' => 'integer',
            'rent_day' => 'integer',
        ];
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(TenancyCharge::class)->orderBy('for_month');
    }

    public function moves(): HasMany
    {
        return $this->hasMany(TenancyMove::class)->orderBy('moved_on')->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** @param  Builder<Tenancy>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /** খাতায় বসা মাসের দাবির যোগ */
    public function charged(): string
    {
        return $this->postedSum($this->charges()->getQuery()->reorder(), 'fin_tenancy_charges');
    }

    /** খাতায় বসা আদায় — নগদে বা জামানত থেকে কেটে */
    public function collected(): string
    {
        return $this->postedSum($this->moves()->getQuery()->reorder()->whereIn('kind', TenancyMove::SETTLES), 'fin_tenancy_moves');
    }

    /** ⭐ বকেয়া — ঋণাত্মক হলে ভাড়াটে আগাম দিয়ে রেখেছেন */
    public function outstanding(): string
    {
        return bcsub($this->charged(), $this->collected(), 4);
    }

    /** ⭐ হাতে রাখা জামানত — নেওয়া − কাটা − ফেরত, কেবল খাতায় বসা */
    public function depositHeld(): string
    {
        $in = $this->postedSum($this->moves()->getQuery()->reorder()->where('kind', TenancyMove::DEPOSIT_IN), 'fin_tenancy_moves');
        $out = $this->postedSum($this->moves()->getQuery()->reorder()->whereIn('kind', [TenancyMove::FROM_DEPOSIT, TenancyMove::REFUND]), 'fin_tenancy_moves');

        return bcsub($in, $out, 4);
    }

    /**
     * ⭐ হাতে রাখা জামানত থেকে সইয়ের অপেক্ষার কাটা ও ফেরত বাদ — নতুন কাটা বা ফেরত এর বেশি নয়, নইলে দুই অপেক্ষার কাগজ একই
     * টাকা দুবার খরচ করত (ভাড়ার চুক্তির [[RentalContract::depositFree()]]-এর শিক্ষা)।
     */
    public function depositFree(): string
    {
        $waiting = (string) $this->moves()->getQuery()->reorder()
            ->whereIn('kind', [TenancyMove::FROM_DEPOSIT, TenancyMove::REFUND])
            ->whereHas('voucher', fn ($v) => $v->where('status', DocumentStatus::DRAFT))
            ->sum('amount');

        return bcsub($this->depositHeld(), $waiting, 4);
    }

    /**
     * ⭐ সবচেয়ে পুরনো না-দেওয়া মাস — আদায় আগে পুরনো মাসে বসে (আগে-আসা-আগে-যায়)। বকেয়া নেই হলে null।
     */
    public function oldestUnpaidMonth(): ?Carbon
    {
        $left = $this->collected();

        foreach ($this->charges()->whereHas('voucher', fn ($v) => $v->where('status', DocumentStatus::CONFIRMED))->get() as $charge) {
            $left = bcsub($left, (string) $charge->amount, 4);

            if (bccomp($left, '0', 4) < 0) {
                return $charge->for_month->copy();
            }
        }

        return null;
    }

    /** @param  Builder<Model>  $rows */
    private function postedSum(Builder $rows, string $table): string
    {
        return bcadd((string) $rows
            ->join('vouchers as pv', 'pv.id', '=', $table.'.voucher_id')
            ->where('pv.status', DocumentStatus::CONFIRMED)
            ->sum($table.'.amount'), '0', 4);
    }

    public static function drillSourceType(): string
    {
        return 'tenancy';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no ?? (string) $this->id;
    }

    public function drillLabel(): string
    {
        return __('finance::tenancy.title').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.tenancy.show', ['tenancy' => $this->id]];
    }
}
