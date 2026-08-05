<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা কর্মীর একটা খাতে, একটা তারিখ থেকে, কত।
 *
 * বেতন বাড়লে পুরনো সারি বদলায় না — নতুন তারিখে নতুন সারি বসে। তাই
 * জুনের বেতনশিট আজ খুললেও জুনের অঙ্কেই দেখা যায়।
 */
class SalaryStructure extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'hr_salary_structures';

    protected $fillable = [
        'company_id', 'employee_id', 'salary_head_id',
        'effective_from', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<SalaryHead, $this> */
    public function salaryHead(): BelongsTo
    {
        return $this->belongsTo(SalaryHead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
