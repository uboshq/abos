<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা গুদাম।
 *
 * শাখার নিচে, ইচ্ছাকৃতভাবে: একটা ডিপোর তিনটা শাখা থাকলে নেত্রকোনার মাল
 * ময়মনসিংহের তালিকায় দেখানো মানে সেলসম্যান এমন জিনিস বেচবেন যা তার
 * শাখায় নেই — আর সেটা ধরা পড়বে মাল দিতে গিয়ে, ক্রেতার সামনে।
 */
class Warehouse extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'inv_warehouses';

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name_en', 'name_bn',
        'address_en', 'address_bn', 'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'warehouse';
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
        return ['inventory.warehouse.index', []];
    }
}
