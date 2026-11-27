<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\ShowsItselfForSigning;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ সরবরাহকারীর বাকির সীমা বাড়ানোর অনুরোধ — সই পড়লে তবেই বসে (মালিক, ১৮ নভেম্বর ২০২৬; [[SupplierLimitRequests]])।
 *
 * ⓘ সইয়ের আগে চলতি সীমা এক পয়সাও বদলায় না, তাই দেয়াল ([[SupplierCreditWall]]) পুরনো সীমাতেই থামায়।
 * ⛔ মোছে না — না হওয়া বা বাতিল অনুরোধও থাকে।
 */
class SupplierCreditRequest extends Model implements ShowsItselfForSigning
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** সই পড়েছে, কিন্তু মাঝে সীমা অন্য পথে বদলেছে — কাগজটা পুরনো, বসেনি */
    public const VOID = 'void';

    protected $table = 'supplier_credit_requests';

    protected $fillable = [
        'company_id', 'branch_id', 'supplier_id', 'current_limit', 'requested_limit', 'reason',
        'status', 'approval_id', 'requested_by', 'decided_at', 'applied_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'current_limit' => 'decimal:4',
            'requested_limit' => 'decimal:4',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $row): void {
            throw new \RuntimeException('সরবরাহকারীর সীমা বাড়ানোর অনুরোধ মোছা যায় না — সারি #'.$row->id.'।');
        });
    }

    /** ⓘ পর্দার অবস্থা — অপেক্ষার অনুরোধ অনুরোধকারী ফিরিয়ে নিলে (ইঞ্জিনে বাতিল) "ফিরিয়ে নেওয়া"; সারির ঘর বদলায় না */
    public function shownStatus(): string
    {
        if ($this->status === self::PENDING && $this->approval?->status === Approval::CANCELLED) {
            return self::CANCELLED;
        }

        return (string) $this->status;
    }

    /** ⭐ সইয়ের পাতায় অনুরোধটা নিজে কী বলে — কার, আগে → চাওয়া, এখনকার বাকি, কেন, কে চাইলেন */
    public function signingSheet(): array
    {
        $supplier = $this->supplier;
        $used = $supplier === null ? null : app(\App\Modules\Supplier\Services\SupplierCreditWall::class)->used($supplier);

        $facts = [
            ['label' => __('supplier::credit_request.supplier'), 'value' => trim(($supplier?->name() ?? '').' '.($supplier?->code === null ? '' : '('.$supplier->code.')'))],
            ['label' => __('supplier::credit_request.from_to'), 'value' => Money::format((string) $this->current_limit).' → '.Money::format((string) $this->requested_limit)],
            ['label' => __('supplier::credit_request.used_now'), 'value' => $used === null ? '' : Money::format($used)],
            ['label' => __('supplier::credit_request.reason'), 'value' => (string) $this->reason],
            ['label' => __('supplier::credit_request.asked_by'), 'value' => (string) ($this->requester?->name ?? '')],
        ];

        return [
            'facts' => array_values(array_filter($facts, fn (array $f) => trim((string) $f['value']) !== '')),
            'columns' => [],
            'rows' => [],
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
