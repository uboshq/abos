<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক মাসের বেতনের রান।
 *
 * খসড়া অবস্থায় অঙ্ক বদলানো যায় (কাঠামো শুধরে আবার বানানো), কিন্তু
 * নিশ্চিত করার পর নয় — তখন সেটা খাতায় বসে গেছে, আর খাতা বদলাতে হলে
 * বিপরীত এন্ট্রি লাগে, সংশোধন নয়।
 */
class PayrollRun extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'hr_payroll_runs';

    /** পোস্টিং ইঞ্জিনে এই নামেই যায় — লেজার থেকে ফিরে আসার সুতো। */
    public const SOURCE_TYPE = 'payroll_run';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'month', 'trx_date',
        'gross_total', 'deduction_total', 'net_total', 'employee_count',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'trx_date' => 'date',
            'gross_total' => 'decimal:4',
            'deduction_total' => 'decimal:4',
            'net_total' => 'decimal:4',
            'employee_count' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এই মাসের রান।
     *
     * শাখা ধরে ভাগ নেই: বেতন পুরো কোম্পানির একটাই কাজ। শাখা ধরে
     * ভাগ করলে যে কর্মীর শাখা বসানো নেই তিনি কোনো রানেই পড়তেন না।
     */
    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereDate('month', $month);
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->month->translatedFormat('F Y');
    }

    public function drillRoute(): array
    {
        return ['hr.payroll.show', ['run' => $this->id]];
    }
}
