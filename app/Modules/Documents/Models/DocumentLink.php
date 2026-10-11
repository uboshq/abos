<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কাগজটা কার সাথে জোড়া — গ্রাহক, সরবরাহকারী, ক্রয়ের কাগজ, কর্মী (§১৫; চতুর্থ ধাপ)।
 *
 * ⓘ `source_type` ABOS-এর ড্রিলের নাম (`customer`, `purchase_order` …), `source_id` তার id —
 * অন্য মডিউলের কোনো ক্লাস এখানে নেই ([[DrillResolver]])।
 */
class DocumentLink extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'dms_document_links';

    protected $fillable = ['company_id', 'document_id', 'source_type', 'source_id', 'created_by', 'updated_by'];

    public function auditIgnores(): array
    {
        return ['updated_by'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
