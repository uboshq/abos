<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * কোম্পানির একটা শাখা।
 *
 * একটা ডিপোর তিনটা শাখা থাকলে প্রতিটার নিজের ডকুমেন্ট সিরিজ ও নিজের এন্ট্রি
 * থাকা দরকার — DMS-এ শাখা ধরা না থাকায় সব এন্ট্রি একটাতেই যেত, আর সেটা ঠিক
 * করতে পরে আলাদা কমিট লেগেছিল।
 */
class Branch extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'address_en', 'address_bn', 'phone', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function name(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->name_bn)) {
            return $this->name_bn;
        }

        return $this->name_en;
    }
}
