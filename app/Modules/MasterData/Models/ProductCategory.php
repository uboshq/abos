<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * পণ্যের শ্রেণি — "বিস্কুট", "সাবান", "পানীয়"।
 *
 * ব্র্যান্ডের সাথেই সারানো হলো, আর একই কারণে: ঘরটা মুক্ত লেখা ছিল, তাই
 * "বিস্কুট" ও "বিস্কিট" দুইটা আলাদা শ্রেণি হয়ে বসত। দুইটা একসাথে করা
 * ইচ্ছাকৃত — একই টেবিলে দুইবার মাইগ্রেশন চালানোর চেয়ে একবারই ভালো, আর
 * ভুলটাও হুবহু একই।
 */
class ProductCategory extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_product_categories';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'product_category';
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
        return ['master_data.list.index', ['kind' => 'product-categories']];
    }
}
