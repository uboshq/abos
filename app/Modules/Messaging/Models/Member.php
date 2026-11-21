<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person in a thread, with "delivered up to" and "read up to" marks and mute.
 *
 * ⛔ Not audited: every open chat moves the marks every few seconds — the audit book would fill with ticks.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $user_id
 * @property bool $is_admin
 * @property bool $muted
 * @property int $delivered_id
 * @property int $read_id
 */
class Member extends Model
{
    use BelongsToCompany;

    protected $table = 'messenger_members';

    protected $fillable = ['thread_id', 'user_id', 'is_admin', 'muted', 'joined_at'];

    protected function casts(): array
    {
        return [
            'thread_id' => 'integer',
            'user_id' => 'integer',
            'is_admin' => 'boolean',
            'muted' => 'boolean',
            'delivered_id' => 'integer',
            'read_id' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
