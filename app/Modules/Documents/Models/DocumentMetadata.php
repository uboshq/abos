<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা কাগজের একটা বাড়তি ঘরের মান ([[MetadataField]])।
 */
class DocumentMetadata extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'dms_document_metadata';

    protected $fillable = ['company_id', 'document_id', 'field_id', 'value'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class, 'field_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
