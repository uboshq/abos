<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা ভার্সনের পড়া লেখা — ব্রাউজারে OCR করা, মানুষের দেখা (§৭; পঞ্চম ধাপ, ৯ অক্টোবর ২০২৬)।
 */
class DocumentOcr extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'dms_document_ocr';

    protected $fillable = [
        'company_id', 'document_id', 'version_id', 'text', 'fields', 'language', 'engine',
        'confidence', 'pages', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['fields' => 'array', 'confidence' => 'decimal:2', 'pages' => 'integer'];
    }

    /** ⓘ লেখাটা লম্বা — অডিটে আগে-পরে পুরোটা যায় না, কেবল "পড়া হলো" */
    public function auditIgnores(): array
    {
        return ['text', 'updated_by'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }
}
