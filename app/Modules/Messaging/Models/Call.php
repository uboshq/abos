<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One 1:1 voice or video call — the log only; the voice and picture run on the office LiveKit and are never stored.
 *
 * ⛔ Not audited: the row itself is the log (who, whom, when answered, when ended, how long), and only the
 * call's own steps move it ([[CallService]]).
 *
 * @property int $id
 * @property string $public_id
 * @property ?int $thread_id
 * @property int $caller_id
 * @property int $callee_id
 * @property bool $video
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $answered_at
 * @property ?\Illuminate\Support\Carbon $ended_at
 * @property ?int $duration_s
 * @property \Illuminate\Support\Carbon $created_at
 */
class Call extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const RINGING = 'ringing';

    public const ANSWERED = 'answered';

    public const DECLINED = 'declined';

    public const MISSED = 'missed';

    public const ENDED = 'ended';

    /** ⓘ nobody answered in this many seconds → missed */
    public const RING_SECONDS = 45;

    protected $table = 'messenger_calls';

    protected $fillable = ['thread_id', 'caller_id', 'callee_id', 'video', 'status'];

    protected function casts(): array
    {
        return [
            'thread_id' => 'integer',
            'caller_id' => 'integer',
            'callee_id' => 'integer',
            'video' => 'boolean',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_s' => 'integer',
        ];
    }

    public function room(): string
    {
        return 'call-'.$this->public_id;
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::RINGING, self::ANSWERED], true);
    }
}
