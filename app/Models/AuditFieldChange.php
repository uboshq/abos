<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * একটা ঘরের পরিবর্তন — পুরাতন মান, নতুন মান।
 *
 * "পরিমাণ ১০ → ১৫" এই একটা সারি। ঘটনাটা উপরের টেবিলে, বিস্তারিত এখানে।
 */
class AuditFieldChange extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $table = 'audit_field_changes';

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'audit_trail_id', 'field', 'old_value', 'new_value',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // উপরের সারির মতোই — লেখা হয়ে গেলে আর ছোঁয়া যায় না
        static::saving(function (self $change) {
            if (! $change->exists) {
                return;
            }

            throw new RuntimeException('An audit field change cannot be edited.');
        });

        static::deleting(function (self $change) {
            throw new RuntimeException('An audit field change cannot be deleted.');
        });
    }

    /** @return BelongsTo<AuditTrail, $this> */
    public function trail(): BelongsTo
    {
        return $this->belongsTo(AuditTrail::class, 'audit_trail_id');
    }
}
