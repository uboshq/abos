<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The photo, file or voice note of one message — on the private disk, served only to the thread's members
 * ([[MessengerFileController]]).
 *
 * ⛔ Not audited: it is part of the message ([[Message]]) and never changes after it lands.
 *
 * @property int $id
 * @property int $message_id
 * @property string $kind
 * @property string $path
 * @property ?string $thumb_path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property ?int $width
 * @property ?int $height
 * @property ?int $duration_ms
 * @property ?array $waveform
 */
class MessageFile extends Model
{
    use BelongsToCompany;

    public const DISK = 'local';

    protected $table = 'messenger_files';

    protected $fillable = [
        'message_id', 'kind', 'path', 'thumb_path', 'name', 'mime', 'size',
        'width', 'height', 'duration_ms', 'waveform',
    ];

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'waveform' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
