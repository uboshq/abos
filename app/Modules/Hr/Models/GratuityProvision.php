<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Modules\Hr\Services\Gratuity;
use App\Core\Contracts\Drillable;
use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ এক মাসের এক শাখার গ্র্যাচুইটি সঞ্চিতি — মাস-শেষের দায় আর তা মেলাতে বসা অঙ্ক (fe, ২১ অক্টোবর ২০২৬, দফা ৮; [[Gratuity]])।
 *
 * ⓘ খাতার দাখিলার উৎস — `source_type` = [[Gratuity::SOURCE_TYPE]], `source_id` = এই সারি।
 */
class GratuityProvision extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    protected $table = 'hr_gratuity_provisions';

    protected $fillable = [
        'company_id', 'branch_id', 'month', 'obligation', 'amount', 'employee_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'obligation' => 'decimal:4',
            'amount' => 'decimal:4',
            'employee_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ── Drillable — খাতার সারি থেকে কাগজে (মালিক, ২৩ নভেম্বর ২০২৬) ───────────

    public static function drillSourceType(): string
    {
        return Gratuity::SOURCE_TYPE;
    }

    public function drillDocumentNo(): string
    {
        return 'GRT-'.$this->month->format('Y-m');
    }

    public function drillLabel(): string
    {
        return $this->month->translatedFormat('F Y');
    }

    public function drillRoute(): array
    {
        return ['hr.gratuity.report', ['on' => $this->month?->copy()->endOfMonth()->toDateString()]];
    }
}
