<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\OpensInReach;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
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
    /*
     * ⭐ রান খোলে নাগাল ধরে, মাথার শাখা ধরে নয় — fe, ২১ অক্টোবর ২০২৬, a6 ([[OpensInReach]], ভাউচারের একই নিয়ম;
     * [[APayrollRunOpensByReachTest]])। ⓘ তালিকা মাথার শাখা মানে (শাখা ছাড়া রান কেবল "সব শাখা"-য়); কিন্তু সরাসরি খোলা —
     * পাতা, ছাপা, ব্যাংকের ফাইল, লিংক, খবর বা সইয়ের পাতা থেকে — মানুষের নাগালে: শাখা ছাড়া রান কোম্পানির নাগালের সবার জন্য,
     * নাগালের বাইরে কেবল এই রানের সইকারী, আর কেবল পড়ায় (GET)। ⛔ আগে মাথায় একটা শাখা বাছা থাকলে শাখা ছাড়া রানের লিংক ৪০৪।
     */
    use OpensInReach;
    use ScopedToUserBranch;
    use SharedAcrossCompaniesWhenAsked;
    use SoftDeletes;

    protected $table = 'hr_payroll_runs';

    /** পোস্টিং ইঞ্জিনে এই নামেই যায় — লেজার থেকে ফিরে আসার সুতো। */
    public const SOURCE_TYPE = 'payroll_run';

    /**
     * ⭐ রানের ধরন — মাসের বেতন, বা উৎসব ভাতা (শ্রম বিধিমালা ২০১৫ বিধি ১১১; a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])।
     * ⓘ আগের সব রান `salary` (কলামের ডিফল্ট)। "এক মাসে একটা জীবিত রান" ধরন ধরে — একই মাসে বেতন আর উৎসব ভাতা দুটোই চলে।
     */
    public const SALARY = 'salary';

    public const BONUS = 'bonus';

    /** @var list<string> */
    public const TYPES = [self::SALARY, self::BONUS];

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no', 'run_type',
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

    /**
     * ⭐ বেতনের সই প্রতিটা বেতনশিট আর তার সারির — কেবল মোটের নয় (হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
     * [[APayrollSignatureCoversEverySlipTest]])।
     *
     * ⛔ আগে কাগজের ছাপ নিত কেবল রানের মাথা (মোট, নিট, লোকসংখ্যা) — `lines()` নেই, তাই সারিহীন। সইয়ের পরে বেতনের কাঠামো
     * বদলে "আবার বানান" চাপলে একজনের ১০,০০০ বাড়িয়ে আরেকজনের ১০,০০০ কমানো যেত: মোট এক, ছাপ এক, পুরনো সইয়েই খাতায় আর
     * ব্যাংকের ফাইলে নতুন মাথাপিছু টাকা। ⓘ এখন শিট আর তার আয়-কাটার সারি ছাপে; "আবার বানান" নতুন শিট লেখে, তাই নতুন সই।
     * ⚠️ ডিপ্লয়ের মুহূর্তে সইয়ের অপেক্ষায় বা সই-হওয়া-অথচ-অনিশ্চিত রান একবার নতুন সই চাইবে — নিরাপদ দিকে।
     *
     * @return list<string>
     */
    public function fingerprintRelations(): array
    {
        return ['payslips.lines'];
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

    /** ⓘ এক ধরনের রান — `salary` বা `bonus` */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('hr_payroll_runs.run_type', $type);
    }

    /** ⓘ পুরনো সারিতে ঘরটা না পড়া থাকলে (null) — মাসের বেতন */
    public function isBonus(): bool
    {
        return ($this->run_type ?? self::SALARY) === self::BONUS;
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
