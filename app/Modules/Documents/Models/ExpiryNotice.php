<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * একটা মেয়াদের খবর গেছে — কোন কাগজ, কোন মেয়াদ, কোন ধাপ (§১২; [[DocumentExpiry]])।
 *
 * ⓘ সারি কেবল বসে, বদলায় না — "পাঠানো হয়েছে" একটা ঘটনা।
 */
class ExpiryNotice extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const UPDATED_AT = null;

    protected $table = 'dms_expiry_notices';

    protected $fillable = ['company_id', 'document_id', 'expiry_date', 'threshold', 'sent_to'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'threshold' => 'integer', 'sent_to' => 'integer'];
    }
}
