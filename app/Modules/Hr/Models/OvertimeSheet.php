<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ একজনের এক মাসের অধিকাল — বাড়তি ঘণ্টা আর ব্যবস্থাপকের সই (BLA ২০০৬ ধারা ১০৮; a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])।
 *
 * ⓘ ঘণ্টা হাজিরা থেকে আসে; টাকার অঙ্ক এখানে নেই — সেটা বেতনের রানে মূল থেকে হিসাব হয়ে বেতনের স্লিপে বসে, তাই এই শিট বেতনের
 * চাবি ছাড়াও দেখা যায়। ⛔ সই হওয়ার পরে ঘণ্টা বদলায় না।
 */
class OvertimeSheet extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    protected $table = 'hr_overtime_sheets';

    /** সইয়ের অপেক্ষায় */
    public const WAITING = 'waiting';

    /** অনুমোদিত — বেতনে যায় */
    public const SIGNED = 'signed';

    protected $fillable = [
        'company_id', 'employee_id', 'month', 'hours', 'days', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'hours' => 'decimal:4',
            'days' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function isSigned(): bool
    {
        return $this->status === self::SIGNED;
    }
}
