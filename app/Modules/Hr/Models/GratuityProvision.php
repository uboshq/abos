<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

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
class GratuityProvision extends Model
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
}
