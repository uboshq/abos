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
 * ⭐ কে কার উপরে — SM · TSM · RSM · DSM (মালিকের উত্তর ৩, ২৬ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ একজন তাঁর নিচের গোটা গাছের বাঁধা ডিলার দেখেন ([[DealerScope::reachOf()]])।
 * ⛔ এক কোম্পানিতে একজনের একজনই উপরওয়ালা (ডাটাবেজের unique), আর নিজের নিচের কাউকে
 * উপরওয়ালা বানানো যায় না ([[DealerBindingService::setSupervisor()]]) — চক্র হলে গাছ
 * আর গাছ থাকে না।
 */
class StaffSupervisor extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'staff_supervisors';

    protected $fillable = ['company_id', 'user_id', 'supervisor_id', 'created_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
