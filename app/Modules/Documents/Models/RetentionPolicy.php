<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * একটা রাখার নিয়ম — কতদিন পরে আর্কাইভ, কতদিন পরে রিসাইকেল বিন (§২০; সপ্তম ধাপ)।
 *
 * ⛔ চিরতরে মোছা নিয়মে কখনো নয় ([[DocumentRetention]])।
 */
class RetentionPolicy extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasPublicId;
    use IsAudited;

    /** @var list<string> */
    public const BASES = ['created', 'document_date', 'expiry_date'];

    protected $table = 'dms_retention_policies';

    protected $fillable = [
        'company_id', 'folder', 'doc_type', 'basis', 'archive_after_days', 'bin_after_days',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'archive_after_days' => 'integer', 'bin_after_days' => 'integer'];
    }

    public function auditIgnores(): array
    {
        return ['updated_by'];
    }

    public function name(): string
    {
        return 'retention #'.$this->getKey();
    }
}
