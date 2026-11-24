<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * One move of a member's delivered/read mark — the source of "message info" (delivered_at, read_at per member).
 *
 * ⛔ Not audited and never changed: each row is written once when a mark moves; it is itself the history.
 *
 * @property int $member_id
 * @property string $kind
 * @property int $up_to
 * @property \Illuminate\Support\Carbon $at
 */
class MemberMark extends Model
{
    use BelongsToCompany;
    use \App\Modules\Messaging\Models\Concerns\FollowsThePerson;
    use HasPublicId;

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public $timestamps = false;

    protected $table = 'messenger_marks';

    protected $fillable = ['member_id', 'kind', 'up_to', 'at'];

    protected function casts(): array
    {
        return ['member_id' => 'integer', 'up_to' => 'integer', 'at' => 'datetime'];
    }
}
