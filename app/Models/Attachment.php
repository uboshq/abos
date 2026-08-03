<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা সংযুক্ত কাগজ।
 *
 * তালিকা দেখানোর সময় ফাইলের বিষয়বস্তু আসে না — শুধু মেটাডাটা। DMS-এ
 * ফাইলটা base64 হয়ে একই টেবিলে বসত, তাই তালিকা আর বিস্তারিতকে আলাদা করতে
 * হয়েছিল। এখানে ফাইল ডিস্কে, তাই সমস্যাটাই নেই।
 */
class Attachment extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'source_module', 'source_entity', 'source_entity_id',
        'original_name', 'stored_path', 'mime_type', 'extension',
        'size_bytes', 'checksum', 'version', 'replaces_id', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'version' => 'integer'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function scopeFor(Builder $query, string $module, string $entity, int $entityId): Builder
    {
        return $query->where('source_module', $module)
            ->where('source_entity', $entity)
            ->where('source_entity_id', $entityId);
    }

    /** শুধু সর্বশেষ সংস্করণ — পুরনোগুলো ইতিহাসে থাকে। */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotIn('id', function ($sub) {
            $sub->select('replaces_id')->from('attachments')->whereNotNull('replaces_id');
        });
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size_bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }
}
