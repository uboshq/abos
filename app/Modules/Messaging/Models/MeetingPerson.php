<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person of one meeting: role, where they stand, and how long they were in.
 */
class MeetingPerson extends Model
{
    use BelongsToCompany;
    use \App\Modules\Messaging\Models\Concerns\FollowsThePerson;
    use HasPublicId;
    use IsAudited;

    public const HOST = 'host';

    public const COHOST = 'cohost';

    public const MEMBER = 'member';

    public const INVITED = 'invited';

    public const WAITING = 'waiting';

    public const IN = 'in';

    public const LEFT = 'left';

    public const REMOVED = 'removed';

    public const DECLINED = 'declined';

    protected $table = 'messenger_meeting_people';

    protected $fillable = ['company_id', 'meeting_id', 'user_id', 'role', 'state', 'invited_at', 'joined_at', 'left_at', 'seen_at', 'session', 'seconds'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'joined_at' => 'datetime', 'left_at' => 'datetime', 'seen_at' => 'datetime', 'seconds' => 'integer'];
    }

    /**
     * ⓘ the heartbeat and the LiveKit session move by themselves — not a person's doing
     *
     * @return list<string>
     */
    public function auditIgnores(): array
    {
        return ['seen_at', 'session'];
    }

    public function runsIt(): bool
    {
        return in_array($this->role, [self::HOST, self::COHOST], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
