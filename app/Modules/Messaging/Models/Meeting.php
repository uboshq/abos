<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A meeting — or a group call, when it hangs on a group thread. One engine ([[MeetingService]]).
 *
 * ⛔ The person is the wall: every read goes through [[MeetingService::forUser()]].
 */
class Meeting extends Model
{
    use BelongsToCompany;
    use \App\Modules\Messaging\Models\Concerns\FollowsThePerson;
    use HasPublicId;
    use IsAudited;

    public const MAX_PEOPLE = 10;

    /** ⓘ nobody inside for this long → over */
    public const EMPTY_SECONDS = 300;

    public const EVERYONE = 'everyone';

    public const HOSTS = 'hosts';

    protected $table = 'messenger_meetings';

    protected $fillable = ['thread_id', 'host_id', 'client_key', 'title', 'video', 'locked', 'lobby', 'allow_share', 'started_at', 'empty_since', 'ended_at'];

    protected function casts(): array
    {
        return ['video' => 'boolean', 'locked' => 'boolean', 'lobby' => 'boolean',
            'started_at' => 'datetime', 'empty_since' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function room(): string
    {
        return 'meet-'.$this->public_id;
    }

    public function isOver(): bool
    {
        return $this->ended_at !== null;
    }

    public function people(): HasMany
    {
        return $this->hasMany(MeetingPerson::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }
}
