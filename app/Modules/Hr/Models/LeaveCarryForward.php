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
 * ⭐ অর্জিত ছুটির জের — শেষ হওয়া বছর থেকে `year`-এ ঢোকা দিন (শ্রম আইন ধারা ১১৭; [[EarnedLeave::carryForward()]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ বছর শেষের কাজই কেবল লেখে; হাতে বসানোর পর্দা নেই — জেরটা হাজিরা আর মঞ্জুর হওয়া ছুটি থেকে গোনা, মত নয়। অডিট মডেলের ঘটনা
 * থেকে, তাই আবার চালালে অঙ্ক বদলালে আগের আর নতুন মান খাতায় থাকে।
 */
class LeaveCarryForward extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    protected $table = 'hr_leave_carry_forwards';

    protected $fillable = ['company_id', 'employee_id', 'leave_type_id', 'year', 'days'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'days' => 'decimal:1',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
