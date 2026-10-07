<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ListedInViewedBranch;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\SettledByAVoucher;
use App\Core\Contracts\SettlementTerms;
use App\Core\Contracts\SignedBeforeItIsPaid;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ কর্মীর টাকা চাওয়া — খরচের দাবি বা অগ্রিম অনুরোধ (মালিকের আদেশ, ৭ অক্টোবর ২০২৬, ভাগ ১৩-গ; [[ExpenseClaimService]])।
 *
 * ── অবস্থা ─────────────────────────────────────────────────────────────────
 *   submitted → (শেষ সই) approved → (ক্যাশিয়ার খসড়া পাকা করেন) paid
 *            ↘ (সইয়ে "না") rejected
 *
 * ⓘ খরচের দাবি আগে কর্মীর খোলা অগ্রিম থেকে মেটে (`from_advance`, সাথে সাথে খাতায় — টাকা নড়ে না); বাকিটুকু নগদে, ক্যাশিয়ারের
 * খসড়া ভাউচারে (`payment_voucher_id`)। ভাউচার পাকা হলে কাগজ নিজে "টাকা দেওয়া হয়েছে" ([[settleWith()]]); ভাউচার বাতিল হলে
 * আবার অনুমোদিত ([[unsettle()]])।
 */
class ExpenseClaim extends Model implements Drillable, SettledByAVoucher, SettlementTerms, SignedBeforeItIsPaid
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ListedInViewedBranch;

    // ⓘ কর্মীর তালিকা দলীয় হলে তাঁর কাগজও — বেতনের রানের একই নিয়ম ([[TheStaffRegisterCanBeOneForTheGroupTest]])
    use SharedAcrossCompaniesWhenAsked;

    public const EXPENSE = 'expense';

    public const ADVANCE = 'advance';

    /** @var list<string> */
    public const KINDS = [self::EXPENSE, self::ADVANCE];

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    /** @var list<string> */
    public const STATES = [self::SUBMITTED, self::APPROVED, self::PAID, self::REJECTED];

    /** অনুমোদনের কাজের নাম — ধরন ধরে, যাতে দুইটার আলাদা সীমা বসানো যায় */
    public const ACTION_EXPENSE = 'expense_claim';

    public const ACTION_ADVANCE = 'cash_advance';

    public const ACTIONS = [self::EXPENSE => self::ACTION_EXPENSE, self::ADVANCE => self::ACTION_ADVANCE];

    protected $table = 'hr_expense_claims';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'kind', 'employee_id', 'expense_account_id', 'amount', 'from_advance',
        'spent_on', 'reason', 'status', 'settle_voucher_id', 'payment_voucher_id', 'decided_at', 'paid_at', 'requested_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'from_advance' => 'decimal:2',
            'spent_on' => 'date',
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function paymentVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'payment_voucher_id');
    }

    public function settleVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'settle_voucher_id');
    }

    public function action(): string
    {
        return self::ACTIONS[$this->kind];
    }

    /** নগদে যা দিতে হবে — অগ্রিম থেকে কাটার পরে */
    public function cashPart(): string
    {
        return bcsub((string) $this->amount, (string) $this->from_advance, 2);
    }

    // ── ভাউচারের সাথে মেলা ([[VoucherService::assertAgainstFits()]], [[VoucherService::settle()]]) ──

    /** @return array{voucher_type: string, amount: string, party_type: string, party_id: int, party_required: bool, open: bool} */
    public function settlementTerms(): array
    {
        return [
            'voucher_type' => Voucher::PAYMENT,
            'amount' => $this->cashPart(),
            'party_type' => Employee::drillSourceType(),
            'party_id' => (int) $this->employee_id,
            'party_required' => true,
            // ⓘ কেবল অনুমোদিত আর এখনো না-দেওয়া — সইয়ের আগে বা দুবার নয়
            'open' => $this->status === self::APPROVED,
        ];
    }

    public function settleWith(int $voucherId): void
    {
        $this->forceFill(['status' => self::PAID, 'paid_at' => now(), 'payment_voucher_id' => $voucherId])->save();
    }

    public function unsettle(int $voucherId): void
    {
        if ((int) $this->payment_voucher_id === $voucherId && $this->status === self::PAID) {
            $this->forceFill(['status' => self::APPROVED, 'paid_at' => null])->save();
        }
    }

    /** ⓘ শেষ সই কাগজেই পড়েছে — ক্যাশিয়ারের ভাউচার আবার সই চায় না ([[VoucherApproval::actionFor()]]) */
    public function signedBeforeItIsPaid(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PAID], true);
    }

    // ── Drillable ──

    public static function drillSourceType(): string
    {
        return 'expense_claim';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no ?? (string) $this->id;
    }

    public function drillLabel(): string
    {
        return __('hr::claim.kind_'.$this->kind).' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['hr.claim.show', ['claim' => $this->id]];
    }
}
