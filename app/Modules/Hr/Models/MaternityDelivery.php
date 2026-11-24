<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ প্রসবের আসল দিনের অনুরোধ — সই পড়লে মাতৃত্বকালীন ছুটির শেষ দিন সরে (ধারা ৪৫, ৪৭; [[MaternityDeliveryService]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ অবস্থা: সইয়ের অপেক্ষায় (`pending`) · অনুমোদিত (`approved`) · প্রত্যাখ্যাত (`rejected`)। অডিট মডেলের ঘটনা থেকে।
 */
class MaternityDelivery extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'hr_maternity_deliveries';

    protected $fillable = [
        'company_id', 'leave_application_id', 'employee_id', 'actual_delivery_date', 'old_to_date', 'new_to_date',
        'status', 'adjustment', 'payroll_run_id', 'requested_by', 'decided_by', 'decided_at', 'decision_remarks',
    ];

    protected function casts(): array
    {
        return [
            'actual_delivery_date' => 'date',
            'old_to_date' => 'date',
            'new_to_date' => 'date',
            'adjustment' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LeaveApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
