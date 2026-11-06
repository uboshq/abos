<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ জরুরি সেটিংয়ের একটা চাওয়া বদল, দ্বিতীয় হাতের অপেক্ষায় — কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৭ ([[SignedSettings]])।
 */
class SettingChange extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const PENDING = 'pending';

    public const SIGNED = 'signed';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    protected $table = 'sys_setting_changes';

    protected $fillable = [
        'company_id', 'key', 'old_value', 'new_value', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
