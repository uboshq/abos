<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ ফিল্ড ফোর্সের পদ — SR · ASM · RSM · DSM (মালিক, ১২ নভেম্বর ২০২৬)।
 *
 * ⓘ কেবল গাছে দেখানোর জন্য — দেখার সীমা আসে গাছ আর এলাকা থেকে ([[DealerScope::dealerIds()]])।
 */
class StaffPosition extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** ⓘ নিচ থেকে উপরে — গাছের ক্রম আর পর্দার বোতাম এই ক্রমে */
    public const POSITIONS = ['SR', 'ASM', 'RSM', 'DSM'];

    protected $table = 'staff_positions';

    protected $fillable = ['company_id', 'user_id', 'position', 'created_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
