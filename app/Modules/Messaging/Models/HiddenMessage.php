<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * "Delete for me" — one member stops seeing one message; everyone else still does.
 *
 * ⛔ Not audited: a person tidying their own chat is not a business event, and the message itself is untouched.
 *
 * @property int $message_id
 * @property int $user_id
 */
class HiddenMessage extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'messenger_hidden';

    protected $fillable = ['message_id', 'user_id'];

    protected function casts(): array
    {
        return ['message_id' => 'integer', 'user_id' => 'integer'];
    }
}
