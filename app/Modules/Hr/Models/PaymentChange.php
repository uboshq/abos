<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Core\Contracts\ShownInOwnerSelfReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ কর্মীর বেতনের ঠিকানার একটা বদল — দ্বিতীয় সইয়ের অপেক্ষায়, বা তার ফল (#৮, ২০ অক্টোবর ২০২৬; [[PaymentChanges]])।
 *
 * ⓘ `proposed` = যা বসতে চাওয়া হলো; `previous` = সই পড়ার মুহূর্তে কর্মীর সারিতে যা ছিল। দুটোই ঢাকা, আর নিরীক্ষার খাতায়
 * কেবল "ঢাকা" লেখা হয় ([[AuditEngine::encrypted()]]) — মান নয়।
 *
 * @property array<string, mixed> $proposed
 * @property array<string, mixed>|null $previous
 */
class PaymentChange extends Model implements ShownInOwnerSelfReport
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** বেতন যেখানে যায় — এর যেকোনোটা বদলালে দ্বিতীয় সই */
    public const FIELDS = [
        'payment_method', 'bank_name', 'bank_branch', 'bank_account_name', 'bank_account_no', 'bank_routing_no', 'mfs_number',
    ];

    protected $table = 'hr_payment_changes';

    protected $fillable = [
        'company_id', 'employee_id', 'proposed', 'previous', 'status', 'requested_by', 'decided_by', 'decided_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'proposed' => 'encrypted:array',
            'previous' => 'encrypted:array',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** ⓘ মালিকের নিজে-অনুমোদিত কাগজের রিপোর্টে — লিংক কর্মীর পাতায় */
    public static function ownerSelfSource(): array
    {
        return [Employee::drillSourceType(), 'employee_id'];
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
