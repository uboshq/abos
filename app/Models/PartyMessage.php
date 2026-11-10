<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ পার্টিকে পাঠানোর খাতা — কে, কবে, কোন কাগজ, কোন নম্বরে, কোন ভাষায়, ঠিক কোন লেখা।
 *
 * ⚠️ একটা সারি মানে "WhatsApp খোলা হয়েছে, লেখা ও নম্বর বসানো" — শেষে পাঠান চাপা হয়েছে কি না ব্রাউজার জানে না,
 * তাই খাতাও তা দাবি করে না।
 */
class PartyMessage extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    // ⓘ খাতাও শাখার দেয়ালের ভিতরে — নাগালের বাইরের শাখার পার্টিকে কী গেল, তা দেখা যায় না
    use ScopedToUserBranch;

    protected $fillable = [
        'company_id', 'branch_id', 'channel', 'document_type', 'document_id', 'document_no',
        'party_type', 'party_id', 'party_name', 'phone', 'locale', 'template_id', 'body', 'share_id', 'sent_by',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PartyMessageTemplate::class, 'template_id');
    }
}
