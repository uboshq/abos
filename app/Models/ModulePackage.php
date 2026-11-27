<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ⭐ একগুচ্ছ মডিউলের নাম — শাখায় একসাথে বসানোর ছাঁচ ([[App\Modules\SystemAdmin\Support\ModulePackages]]; মালিক, ২৪ নভেম্বর ২০২৬) */
final class ModulePackage extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = ['company_id', 'name_en', 'name_bn', 'modules', 'created_by'];

    protected function casts(): array
    {
        return ['modules' => 'array'];
    }

    public function name(): string
    {
        return app()->getLocale() === 'bn' && filled($this->name_bn) ? (string) $this->name_bn : (string) $this->name_en;
    }

    /** @return HasMany<BranchModulePackage, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(BranchModulePackage::class, 'package_id');
    }
}
