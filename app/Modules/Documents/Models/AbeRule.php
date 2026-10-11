<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * কোম্পানির নিজের ABE নিয়ম — শ্রেণি চেনার শব্দ, বা তথ্য তোলার প্যাটার্ন (§৮, §২০; ষষ্ঠ ধাপ)।
 */
class AbeRule extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasPublicId;
    use IsAudited;

    public const CLASSIFY = 'classify';

    public const EXTRACT = 'extract';

    protected $table = 'dms_abe_rules';

    protected $fillable = [
        'company_id', 'kind', 'doc_type', 'label', 'keywords', 'pattern', 'weight',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'weight' => 'integer'];
    }

    public function auditIgnores(): array
    {
        return ['updated_by'];
    }

    /** অডিটের লেবেল */
    public function name(): string
    {
        return (string) ($this->attributes['label'] ?? $this->attributes['doc_type'] ?? '');
    }

    /** @return list<string> */
    public function keywordList(): array
    {
        return array_values(array_filter(array_map(
            fn ($w) => mb_strtolower(trim($w)),
            explode(',', (string) $this->keywords),
        ), fn ($w) => $w !== ''));
    }
}
