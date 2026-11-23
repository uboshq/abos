<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ "বার্তা পাঠাবেন না" — a party the company never messages automatically (sample HUXqpfhxng8823eEFrvH54, tab ঙ,
 * "বাদ রাখা পার্টি"). ⓘ A person can still press a button on a paper; only the automatic sends skip them.
 *
 * ⓘ Audited: who put a party on the list, and who took them off, is a business decision.
 *
 * @property string $party_type
 * @property int $party_id
 * @property ?string $party_name
 * @property ?string $reason
 */
class PartyMessageOptout extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'party_message_optouts';

    protected $fillable = ['company_id', 'party_type', 'party_id', 'party_name', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['party_id' => 'integer'];
    }

    public static function covers(string $partyType, int $partyId): bool
    {
        return static::query()->where('party_type', $partyType)->where('party_id', $partyId)->exists();
    }
}
