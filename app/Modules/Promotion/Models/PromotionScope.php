<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Promotion\Support\ScopeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * অফারটা কার জন্য, কোন পণ্যে — একটা দিক, একটা সারি।
 *
 * ⚠️ সারি না থাকা মানে *"সব"*। ⓘ একটা অফারে কোনো `customer` সারি না
 * থাকলে সে সব ক্রেতার জন্য — আর ইঞ্জিন ঠিক তাই পড়ে।
 */
class PromotionScope extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = ['promotion_id', 'kind', 'target_id'];

    protected function casts(): array
    {
        return [
            'kind' => ScopeKind::class,
            'target_id' => 'integer',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
