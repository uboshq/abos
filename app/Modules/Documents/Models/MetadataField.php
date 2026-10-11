<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * কাগজের একটা বাড়তি ঘর — যেমন "লাইসেন্স নম্বর", "চুক্তির পক্ষ" (§২০ Metadata Fields)।
 *
 * ⓘ ধরন (`doc_type`) দিলে কেবল ঐ ধরনের কাগজে আসে; ফাঁকা মানে সব কাগজে।
 * ⓘ ঘরের ধরন তিনটা — লেখা, সংখ্যা, তারিখ ([[KINDS]]); যাচাই সেই ধরনে।
 */
class MetadataField extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;

    /** @var list<string> */
    public const KINDS = ['text', 'number', 'date'];

    protected $table = 'dms_metadata_fields';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'kind', 'doc_type',
        'is_required', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_required' => 'boolean'];
    }

    public function auditIgnores(): array
    {
        return ['updated_by'];
    }

    /** এই ধরনের কাগজে ঘরটা আসে কি না */
    public function appliesTo(?string $docType): bool
    {
        return $this->doc_type === null || $this->doc_type === '' || $this->doc_type === $docType;
    }
}
