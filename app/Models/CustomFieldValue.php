<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা রেকর্ডের একটা নিজস্ব ঘরের মান।
 *
 * ঘরটা নিষ্ক্রিয় করা যায়, কিন্তু মান মোছা হয় না — পুরনো রেকর্ডে লেখা
 * তথ্য হারানো চলবে না, আর ঘরটা আবার চালু করলে সব ফিরে আসে।
 */
class CustomFieldValue extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'company_id', 'custom_field_id', 'entity', 'entity_id', 'value',
    ];

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
}
