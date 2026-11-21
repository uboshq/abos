<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One message.
 *
 * ⛔ Not audited, on purpose: the audit trail copies a row's fields, and the owner's rule is that nobody reads
 * another person's messages except through the written-reason audit door ([[MessengerAudit]]). A copy of every
 * message in the audit book would be a second, unguarded place to read them.
 * ⓘ No edit. "Delete for everyone" only stamps `deleted_at` (a tombstone); the row keeps what was said, for the
 * audit door alone — members see "এই বার্তাটি মুছে ফেলা হয়েছে" ([[MessengerPresenter::message()]]).
 *
 * @property int $id
 * @property int $thread_id
 * @property ?int $sender_id
 * @property ?string $client_id
 * @property string $kind
 * @property ?string $text
 * @property ?int $reply_to_id
 * @property ?string $paper_type
 * @property ?string $paper_id
 * @property ?string $paper_no
 * @property ?string $lat
 * @property ?string $lng
 * @property ?array $meta
 * @property \Illuminate\Support\Carbon $created_at
 */
class Message extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const KINDS = ['text', 'image', 'file', 'voice', 'paper', 'location', 'system'];

    /** ⓘ the kinds that carry a file */
    public const FILE_KINDS = ['image', 'file', 'voice'];

    protected $table = 'messenger_messages';

    protected $fillable = [
        'thread_id', 'sender_id', 'client_id', 'kind', 'text', 'reply_to_id',
        'paper_type', 'paper_id', 'paper_no', 'lat', 'lng', 'meta', 'forwarded',
    ];

    /** ⓘ "delete for everyone" is allowed this long after sending (owner, sample v7 — WhatsApp's window) */
    public const DELETE_WINDOW_MINUTES = 60;

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    protected function casts(): array
    {
        return [
            'thread_id' => 'integer',
            'sender_id' => 'integer',
            'reply_to_id' => 'integer',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'meta' => 'array',
            'forwarded' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function file(): HasOne
    {
        return $this->hasOne(MessageFile::class);
    }
}
