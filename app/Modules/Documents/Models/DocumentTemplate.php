<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * একটা ছাঁচ — লেখা, আর তার ভিতরে `{{ নাম }}` ঘর (§২, §২১; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬)।
 */
class DocumentTemplate extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasPublicId;
    use IsAudited;

    protected $table = 'dms_templates';

    protected $fillable = ['company_id', 'code', 'title', 'doc_type', 'folder', 'body', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditIgnores(): array
    {
        return ['updated_by'];
    }

    public function name(): string
    {
        return (string) ($this->attributes['title'] ?? '');
    }
}
