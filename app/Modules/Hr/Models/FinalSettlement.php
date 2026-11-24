<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Core\Contracts\ShownInOwnerSelfReport;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ চাকরি ছাড়া একজন কর্মীর চূড়ান্ত পাওনা — এক কাগজ, মালিকের অনুমোদনে (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlements]])।
 *
 * ⓘ খাতার দাখিলার উৎস — `source_type` = [[FinalSettlements::SOURCE_TYPE]], `source_id` = এই সারি; বসে কেবল শেষ অনুমোদনে।
 *
 * @property array<string, mixed>|null $details
 */
class FinalSettlement extends Model implements ShownInOwnerSelfReport
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    /** চাকরি শেষের ধরন — BLA ধারা 22–28 আর মৃত্যু (ধারা 19) */
    public const SEPARATIONS = ['resignation', 'termination', 'discharge', 'dismissal', 'retirement', 'death', 'contract_end'];

    protected $table = 'hr_final_settlements';

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'last_day', 'separation', 'note',
        'wages', 'leave_encashment', 'gratuity', 'provident_fund', 'advance_recovered', 'net', 'details',
        'status', 'requested_by', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'last_day' => 'date',
            'wages' => 'decimal:4',
            'leave_encashment' => 'decimal:4',
            'gratuity' => 'decimal:4',
            'provident_fund' => 'decimal:4',
            'advance_recovered' => 'decimal:4',
            'net' => 'decimal:4',
            'details' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** ⓘ মালিকের নিজে-অনুমোদিত কাগজের রিপোর্টে — লিংক কর্মীর পাতায় */
    public static function ownerSelfSource(): array
    {
        return [Employee::drillSourceType(), 'employee_id'];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** ⓘ পাওনার যোগ — অগ্রিম কাটার আগে */
    public function earnings(): string
    {
        return bcadd(bcadd((string) $this->wages, (string) $this->leave_encashment, 4),
            bcadd((string) $this->gratuity, (string) $this->provident_fund, 4), 4);
    }
}
