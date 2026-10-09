<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * একটা ডকুমেন্টের একটা ভার্সন — v1.0, v1.1, v2.0 (§৯; ৮ অক্টোবর ২০২৬)।
 *
 * ── ⛔ ভার্সন কখনো বদলায় না, মোছেও না ──────────────────────────────────
 * পরিকল্পনা §৯: *"Approved document সরাসরি overwrite করা যাবে না"*। ⭐ এখানে নিয়মটা
 * আরও কড়া — কোনো ভার্সনই নিজের জায়গায় বদলায় না: নতুন ফাইল মানে নতুন সারি, পুরনো
 * ফাইল যেমন ছিল তেমন নামানো যায়। ⓘ অডিটের খাতার মতোই ([[AuditTrail]]) তৈরির পরে
 * যেকোনো সংরক্ষণ বা মোছা থেমে যায়।
 *
 * ⓘ ফাইলটা ABOS-এর সংযুক্তির খাতায় ([[Attachment]]) — এই সারি কেবল বলে কোন
 * সংযুক্তিটা কোন ভার্সন। পুরনো ভার্সন ফেরালে নতুন সারি একই সংযুক্তি দেখায়; ফাইলের
 * কপি হয় না, কারণ ফাইলটা তো বদলায়ই না।
 */
class DocumentVersion extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'dms_document_versions';

    /** ⓘ বদলায় না, তাই updated_at নেই */
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'branch_id', 'document_id', 'major', 'minor',
        'attachment_id', 'restored_from_id', 'comment', 'created_by',
    ];

    protected function casts(): array
    {
        return ['major' => 'integer', 'minor' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $version) {
            if (! $version->exists) {
                return;
            }

            throw new RuntimeException('A document version cannot be changed. A change is a new version.');
        });

        static::deleting(function () {
            throw new RuntimeException('A document version cannot be deleted. Its file stays downloadable.');
        });
    }

    /** "1.1" — ভার্সনের নাম, সামনে v ছাড়া */
    public function label(): string
    {
        return $this->major.'.'.$this->minor;
    }

    /** অডিটের লেবেল ([[AuditEngine::labelFor()]]) */
    public function name(): string
    {
        return 'v'.$this->label();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_id');
    }
}
