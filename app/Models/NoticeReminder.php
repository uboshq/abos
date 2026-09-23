<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কাকে কততম তাগাদা দেওয়া হয়েছে.
 *
 * ⓘ `round` সারিতে লেখা থাকে, গোনা হয় না — ⚠️ সারি গুনে বের করলে একটা
 * সারি মুছে গেলে তাগাদা আবার প্রথম থেকে শুরু হত, আর মানুষ একই তাগাদা
 * দুইবার পেতেন.
 */
final class NoticeReminder extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = ['company_id', 'notice_id', 'user_id', 'round', 'sent_at', 'escalated'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'escalated' => 'boolean', 'round' => 'integer'];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
