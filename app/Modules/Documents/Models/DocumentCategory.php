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
 * একটা কোম্পানির নিজের ফোল্ডার (ক্যাটাগরি) — প্রশাসনের পর্দা থেকে (§২০)।
 *
 * ⓘ মালিকের দেওয়া তালিকা কোডেই থাকে ([[DocumentCatalog]]); এই সারি কেবল যা কোম্পানি নিজে
 * যোগ করে। ⚠️ `code` কাগজের সারিতে লেখা থাকে, তাই একবার বসলে বদলায় না — নাম বদলায়, বন্ধ হয়।
 */
class DocumentCategory extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;

    protected $table = 'dms_categories';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** ⓘ যন্ত্রের হিসাব অডিটে যায় না */
    public function auditIgnores(): array
    {
        return ['updated_by'];
    }
}
