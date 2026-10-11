<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * একটা সই — কে, কবে, কোন স্তরে, কোন ভার্সনের কোন SHA-256-এ (§১১; চতুর্থ ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⛔ সই কখনো বদলায় না, মোছেও না — ভার্সনের মতোই ([[DocumentVersion]])।
 */
class DocumentSignature extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const UPDATED_AT = null;

    protected $table = 'dms_signatures';

    protected $fillable = [
        'company_id', 'document_id', 'version_id', 'file_hash', 'approval_id', 'user_id', 'level', 'signed_at',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'level' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $signature) {
            if ($signature->exists) {
                throw new RuntimeException('A signature cannot be changed.');
            }
        });

        static::deleting(function () {
            throw new RuntimeException('A signature cannot be deleted.');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
