<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ⭐ নিয়মের একটা সংস্করণের পুরো ছবি — কে কখন বদলালেন; একবার লেখা, আর বদলায় না (ধাপ ৩) */
class NotificationRuleVersion extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'rule_id', 'version', 'snapshot', 'changed_by'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
