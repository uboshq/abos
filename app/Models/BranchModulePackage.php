<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** কোন শাখায় কোন মডিউল প্যাকেজ — আর শাখাটা হাতে বদলানো কি না ([[ModulePackage]]) */
final class BranchModulePackage extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = ['company_id', 'branch_id', 'package_id', 'is_custom', 'created_by'];

    protected function casts(): array
    {
        return ['is_custom' => 'boolean'];
    }

    /** @return BelongsTo<ModulePackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ModulePackage::class, 'package_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
