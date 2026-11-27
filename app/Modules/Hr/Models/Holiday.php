<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ একটা ছুটির দিন — সরকারি ছুটি বা উৎসব, সব শাখার বা একটা শাখার (বাংলাদেশ শ্রম আইন ২০০৬ ধারা ১১৮; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ সাপ্তাহিক ছুটি এখানে নেই — সেটা সেটিং `hr.weekly_off` ([[WorkCalendar]])। ⓘ নাম একটা ঘরে, দুই ভাষার আলাদা ঘর নয়: "বিজয় দিবস"
 * প্রতি বছর একই নামে ফেরে, তাই নামে নকল-পাহারা অর্থহীন; পাহারা তারিখ আর শাখায় ([[HolidayController]])।
 * ⓘ অডিট মডেলের ঘটনা থেকে ([[IsAudited]]) — বসানো, বদলানো, মোছা তিনটাই খাতায়।
 */
class Holiday extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    // ⓘ HR-এর প্রতিটা কোম্পানি-ছাঁকা মডেলের মতো "গোটা গ্রুপের এক খাতা" সুইচ মানে ([[TheStaffRegisterCanBeOneForTheGroupTest]]);
    // গোনা ([[WorkCalendar]]) তবু কর্মীর নিজের কোম্পানি ধরে
    use SharedAcrossCompaniesWhenAsked;

    protected $table = 'hr_holidays';

    protected $fillable = [
        'company_id', 'branch_id', 'holiday_date', 'name', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
        ];
    }

    /** @return BelongsTo<Branch, $this> খালি = সব শাখা */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
