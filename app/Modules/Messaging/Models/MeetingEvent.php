<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a host did in a meeting, and to whom — append only.
 */
class MeetingEvent extends Model
{
    use BelongsToCompany;
    use \App\Modules\Messaging\Models\Concerns\FollowsThePerson;
    use HasPublicId;
    use IsAudited;

    protected $table = 'messenger_meeting_events';

    protected $fillable = ['company_id', 'meeting_id', 'actor_id', 'person_id', 'what'];

    protected function casts(): array
    {
        return [];
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
