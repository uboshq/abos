<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Contracts\Drillable;
use App\Core\Contracts\ShownInOwnerSelfReport;
use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Models\FinancialYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ এক অর্থবছরের শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল — হিসাব আর তার জাবেদা (শ্রম আইন ২০০৬, অধ্যায় ১৫; a6, ২১ অক্টোবর ২০২৬;
 * [[WppfService]])।
 *
 * ⓘ `draft` — হিসাব বসেছে, "সই দিন" এখনো পড়েনি (বা কোম্পানির ছকে সইয়ের অপেক্ষায়); `posted` — জাবেদা খাতায়, আর বদলায় না।
 */
class WppfProvision extends Model implements Drillable, ShownInOwnerSelfReport
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    /** পোস্টিং ইঞ্জিনে এই নামে — লেজার থেকে ফেরার সুতো */
    public const SOURCE_TYPE = 'wppf_provision';

    protected $table = 'hr_wppf_provisions';

    protected $fillable = [
        'company_id', 'financial_year_id', 'period_from', 'period_to',
        'net_profit', 'wppf', 'participation_fund', 'welfare_fund', 'foundation_fund',
        'status', 'created_by', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'net_profit' => 'decimal:4',
            'wppf' => 'decimal:4',
            'participation_fund' => 'decimal:4',
            'welfare_fund' => 'decimal:4',
            'foundation_fund' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FinancialYear, $this> */
    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    // ── Drillable — খাতার সারি থেকে কাগজে (মালিক, ২৩ নভেম্বর ২০২৬) ───────────

    public static function drillSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    /** ⛔ মালিকের নিজে-অনুমোদিত কাগজের রিপোর্টে আসে, অঙ্ক ছাড়া — ৫% দেখলে গোটা মুনাফা জানা যায়, আর রিপোর্টের চাবি হিসাবের, WPPF-এর নয় */
    public static function ownerSelfSource(): array
    {
        return [self::SOURCE_TYPE, 'id'];
    }

    public function drillDocumentNo(): string
    {
        return 'WPPF-'.$this->id;
    }

    public function drillLabel(): string
    {
        return (string) $this->financialYear?->name;
    }

    public function drillRoute(): array
    {
        return ['hr.wppf.show', ['year' => $this->financial_year_id]];
    }
}
