<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserDealers;
use App\Core\Contracts\ShowsItselfForSigning;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধ — হিসাব বা ম্যানেজার লেখেন, মালিক সই দিলে তবেই বসে (মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬; ধাপ ২.৫)।
 *
 * ⓘ দুই রকম: সাধারণ সীমা (`limit`), বা শেষ দিন পর্যন্ত অস্থায়ী সীমা (`temporary`)। সইয়ের আগে চলতি সীমা এক পয়সাও বদলায়
 * না ([[Customer::effectiveCreditLimit()]] কেবল গ্রাহকের সারি পড়ে)। সই পড়লে বসায় [[CreditLimitRequests::applySigned()]]।
 * ⛔ মোছে না — না হওয়া, ফিরিয়ে নেওয়া বা মেয়াদ পেরোনো অনুরোধও থাকে।
 */
class CustomerCreditRequest extends Model implements ShowsItselfForSigning
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    // ⛔ বিক্রয়কর্মী কেবল নিজের ডিলারের অনুরোধ দেখেন (⛔১৬)
    use ScopedToUserDealers;

    public const LIMIT = 'limit';

    public const TEMPORARY = 'temporary';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** সই পড়েছে, কিন্তু মালিকের নয় — বসেনি */
    public const VOID = 'void';

    protected $table = 'customer_credit_requests';

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'kind', 'current_limit', 'requested_limit', 'temp_until', 'reason',
        'status', 'approval_id', 'requested_by', 'decided_at', 'applied_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'current_limit' => 'decimal:4',
            'requested_limit' => 'decimal:4',
            'temp_until' => 'date',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $row): void {
            throw new \RuntimeException('সীমা বাড়ানোর অনুরোধ মোছা যায় না — সারি #'.$row->id.'।');
        });
    }

    /**
     * ⓘ পর্দার অবস্থা — অপেক্ষার অনুরোধ যদি অনুরোধকারী ফিরিয়ে নিয়ে থাকেন (ইঞ্জিনে বাতিল, ঘটনা ছোটে না) বা অস্থায়ী সীমার শেষ
     * দিন সইয়ের আগেই পেরিয়ে যায়, তবে তা-ই দেখায়। সারির ঘর বদলায় না।
     */
    public function shownStatus(): string
    {
        if ($this->status !== self::PENDING) {
            return (string) $this->status;
        }

        if ($this->approval?->status === Approval::CANCELLED) {
            return self::CANCELLED;
        }

        if ($this->kind === self::TEMPORARY && $this->temp_until !== null && Carbon::today()->gt($this->temp_until)) {
            return self::EXPIRED;
        }

        return self::PENDING;
    }

    /**
     * ⭐ সইয়ের পাতায় অনুরোধটা নিজে কী বলে — কার, কী চাই, আগে → চাওয়া, কবে পর্যন্ত, কেন, কে চাইলেন (ফোনের অনুমোদনের বাক্স,
     * মালিক, ১৩ অক্টোবর ২০২৬)। ⓘ আগে বাক্সে কেবল "CustomerCreditRequest" আর এক লাইনের কথা দেখাত; মালিক সই দেন এখান থেকেই।
     * ⛔ কোনো হিসাব নেই — সারির নিজের ঘর, লেখা হয়ে।
     */
    public function signingSheet(): array
    {
        $facts = [
            ['label' => __('customer::credit_request.customer'), 'value' => trim(($this->customer?->name() ?? '').' '.($this->customer?->code === null ? '' : '('.$this->customer->code.')'))],
            ['label' => __('customer::credit_request.kind'), 'value' => __('customer::credit_request.kind_'.$this->kind)],
            ['label' => __('customer::credit_request.from_to'), 'value' => Money::format((string) $this->current_limit).' → '.Money::format((string) $this->requested_limit)],
            ['label' => __('customer::credit_request.temp_until'), 'value' => $this->temp_until === null ? '' : DateFormat::format($this->temp_until)],
            ['label' => __('customer::credit_request.reason'), 'value' => (string) $this->reason],
            ['label' => __('customer::credit_request.asked_by'), 'value' => (string) ($this->requester?->name ?? '')],
        ];

        return [
            'facts' => array_values(array_filter($facts, fn (array $f) => trim((string) $f['value']) !== '')),
            'columns' => [],
            'rows' => [],
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
