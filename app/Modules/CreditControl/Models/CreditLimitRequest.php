<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserDealers;
use App\Core\Contracts\ChoosesItsSigners;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\ShowsItselfForSigning;
use App\Core\Services\PermissionSyncer;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ⭐ সীমা বাড়ানোর একটা অনুরোধ — বাকি নিয়ন্ত্রণ ধাপ ২.৫ (মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ লেখেন হিসাবরক্ষক বা ম্যানেজার ([[CreditLimitRequests::raise()]]); সই চায় অনুমোদনের ইঞ্জিনে (`customer.credit_limit` কাজ,
 * সীমা বাড়ানোর একই ছক); সীমা বসে কেবল মালিকের সইয়ে ([[ApplyTheRequestOnTheOwnersSignature]])।
 * ⛔ সই ঝুলে থাকতে চলতি সীমা এক পয়সাও বদলায় না — গ্রাহকের সারিতে কিছুই লেখা হয় না।
 * ⛔ সারি কখনো মোছে না: "না" বা মেয়াদ ফুরানো অনুরোধও থাকে — পরে "কে কবে কত চেয়েছিলেন" প্রশ্নের উত্তর।
 */
class CreditLimitRequest extends Model implements ChoosesItsSigners, Drillable, ShowsItselfForSigning
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

    public const EXPIRED = 'expired';

    protected $table = 'cc_limit_requests';

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'kind', 'current_limit', 'requested_limit', 'temporary_until', 'reason',
        'status', 'approval_id', 'requested_by', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'current_limit' => 'decimal:4',
            'requested_limit' => 'decimal:4',
            'temporary_until' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $row): void {
            throw new \RuntimeException('সীমা বাড়ানোর অনুরোধ মোছা যায় না — সারি #'.$row->id.'।');
        });
    }

    /**
     * ⓘ পর্দার অবস্থা — অস্থায়ী সীমার অনুরোধ শেষ দিন পেরিয়ে গেলে সই ছাড়াই "মেয়াদ ফুরানো" (সই পড়লেও আর বসে না)।
     */
    public function shownStatus(): string
    {
        if ($this->status === self::PENDING && $this->isPastItsDay()) {
            return self::EXPIRED;
        }

        return (string) $this->status;
    }

    /** অস্থায়ী সীমার শেষ দিন আজকের আগে — বসানোর আর মানে নেই */
    public function isPastItsDay(): bool
    {
        return $this->kind === self::TEMPORARY
            && $this->temporary_until !== null
            && $this->temporary_until->toDateString() < Carbon::today()->toDateString();
    }

    /**
     * ⭐ কে সই দিতে পারেন — কেবল মালিক (সুপার অ্যাডমিন), আর যিনি অনুরোধ লিখেছেন তিনি নন (মালিকের উত্তর ৫, দায়িত্বের ভাগ)।
     *
     * ⓘ অনুমোদনের ইঞ্জিন সইয়ের আগে কাগজকেই জিজ্ঞেস করে ([[ChoosesItsSigners]]) — ছকের ধাপ যা-ই বলুক, নিজের-সইয়ের সীমা
     * (`approval.self_limit`) যা-ই হোক, এই উত্তর "না" হলে সই হয় না।
     */
    public function mayBeSignedBy(User $user, Approval $approval): bool
    {
        return $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE)
            && (int) $user->id !== (int) $this->requested_by;
    }

    /**
     * ⭐ সই দিতে বসা মালিক যা দেখেন — কার, এখন কত, কত চাওয়া, কবে পর্যন্ত, এখন কত ব্যবহৃত, কেন, কে চাইলেন।
     * ⓘ ব্যবহৃত অঙ্ক দেয়ালের নিজের হিসাব থেকে ([[CreditExposure::positionOf()]]) — দ্বিতীয় সূত্র নয়।
     */
    public function signingSheet(): array
    {
        $customer = $this->customer;
        $position = $customer === null ? null : app(\App\Modules\Sales\Services\CreditExposure::class)->positionOf($customer);
        $money = fn ($v) => \App\Core\Support\Money::format((string) $v);

        $facts = array_values(array_filter([
            ['label' => __('credit_control::request.sheet_customer'), 'value' => (string) $customer?->name()],
            ['label' => __('credit_control::request.kind'), 'value' => __('credit_control::request.kind_'.$this->kind)],
            ['label' => __('credit_control::request.sheet_now'), 'value' => $money($this->current_limit)],
            ['label' => __('credit_control::request.sheet_asked'), 'value' => $money($this->requested_limit)],
            ['label' => __('credit_control::request.until'), 'value' => \App\Core\Support\DateFormat::format($this->temporary_until) ?: ''],
            ['label' => __('credit_control::request.sheet_exposure'), 'value' => $position === null ? '' : $money($position['exposure'])],
            ['label' => __('credit_control::profile.reason'), 'value' => (string) $this->reason],
            ['label' => __('credit_control::request.sheet_by'), 'value' => (string) $this->requester?->name],
        ], fn (array $f) => $f['value'] !== ''));

        return [
            'facts' => $facts,
            'columns' => [],
            'rows' => [],
            'party' => $customer === null ? null : ['type' => 'customer', 'id' => (int) $customer->id],
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    // ── Drillable — সইয়ের পর্দা আর কাগজপত্র এখান থেকে কাগজটা চেনে ──────────

    public static function drillSourceType(): string
    {
        return 'credit_limit_request';
    }

    public function drillDocumentNo(): string
    {
        return 'CLR-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function drillLabel(): string
    {
        return __('credit_control::request.label', ['no' => $this->drillDocumentNo(), 'customer' => (string) $this->customer?->name()]);
    }

    public function drillRoute(): array
    {
        return ['credit_control.customer.show', ['customer' => $this->customer_id]];
    }
}
