<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * নিয়োগের ধরন — স্থায়ী, শিক্ষানবিশ, চুক্তিভিত্তিক, দৈনিক।
 *
 * ধরনটা বেতনের হিসাবে ঢোকে: দৈনিক কর্মীর বেতন হাজিরার সাথে বাঁধা,
 * স্থায়ী কর্মীর নয়। তাই এটা কেবল একটা লেবেল নয়।
 */
class EmploymentType extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_employment_types';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'employment_type';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.employment_type.edit', ['id' => $this->id]];
    }
}
