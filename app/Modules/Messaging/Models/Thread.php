<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation — direct (two people) or a group.
 *
 * ⓘ Audited: a thread row holds only who made it and the group's title — never a message.
 * ⛔ Messages and members are NOT audited (privacy + every poll moves a member's marks); see [[Message]].
 *
 * @property int $id
 * @property string $public_id
 * @property string $kind
 * @property ?string $title
 * @property ?string $direct_key
 * @property int $created_by
 * @property ?int $last_message_id
 * @property ?\Illuminate\Support\Carbon $last_at
 */
class Thread extends Model
{
    use BelongsToCompany;
    use \App\Modules\Messaging\Models\Concerns\FollowsThePerson;
    use HasPublicId;
    use IsAudited;

    public const DIRECT = 'direct';

    public const GROUP = 'group';

    protected $table = 'messenger_threads';

    protected $fillable = ['kind', 'title', 'direct_key', 'created_by'];

    protected function casts(): array
    {
        return [
            'last_at' => 'datetime',
            'last_message_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function isGroup(): bool
    {
        return $this->kind === self::GROUP;
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Thread> $query */
    public function scopeOf(Builder $query, int $userId): Builder
    {
        return $query->whereIn('messenger_threads.id', Member::query()->select('thread_id')->where('user_id', $userId));
    }
}
