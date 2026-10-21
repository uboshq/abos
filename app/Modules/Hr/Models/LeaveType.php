<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ছুটির একটা ধরন — নৈমিত্তিক, অসুস্থতা, বার্ষিক, বিনা বেতনে।
 *
 * বছরে কত দিন তা এখানে, কিন্তু কার কত বাকি তা এখানে নয় — সেটা গোনা
 * হয় মঞ্জুর হওয়া আবেদন থেকে। জমা রাখলে সংখ্যাটা একদিন সত্যি থেকে
 * সরে যেত, আর কোনটা ঠিক তা বলার উপায় থাকত না।
 */
class LeaveType extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SharedAcrossCompaniesWhenAsked;
    use SoftDeletes;

    protected $table = 'hr_leave_types';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'days_per_year', 'is_paid', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'days_per_year' => 'decimal:1',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * ⭐ অর্জিত ছুটির প্রমিত কোড — এর পাওনা বছরে বাঁধা দিন নয়, হাজিরা থেকে অর্জিত (শ্রম আইন ধারা ১১৭; [[EarnedLeave]]; a6, ২১ অক্টোবর ২০২৬)।
     * ⓘ কোড দিয়ে চেনা, কারণ প্রমিত ধরনগুলো এই কোডেই বসে ([[LeaveController::installTypes()]]); অন্য কোডের ধরন আগের মতো `days_per_year` মানে।
     */
    public const EARNED = 'EARNED';

    public function isEarned(): bool
    {
        return $this->code === self::EARNED;
    }

    /** সীমাহীন ছুটি — বিনা বেতনের ছুটিতে বছরের কোটা থাকে না। ⛔ অর্জিত ছুটি কখনো সীমাহীন নয় — সীমা অর্জনটাই। */
    public function hasNoYearlyLimit(): bool
    {
        return ! $this->isEarned() && bccomp((string) $this->days_per_year, '0', 1) <= 0;
    }
}
