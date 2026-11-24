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
     * ⓘ কোড দিয়ে চেনা, কারণ প্রমিত ধরনগুলো এই কোডেই বসে ([[LeaveTypeDefaults]]); অন্য কোডের ধরন আগের মতো `days_per_year` মানে।
     */
    public const EARNED = 'EARNED';

    public function isEarned(): bool
    {
        return $this->code === self::EARNED;
    }

    /**
     * ⭐ মাতৃত্বকালীন ছুটির প্রমিত কোড — ১৬ সপ্তাহ, বেতনসহ, কেবল নারী কর্মী, চাকরি অন্তত ৬ মাস (শ্রম আইন অধ্যায় ৪, ধারা ৪৫–৫০;
     * [[LeaveService::assertEligible()]]; a6, ২১ অক্টোবর ২০২৬)।
     */
    public const MATERNITY = 'MATERNITY';

    /** ১৬ সপ্তাহ */
    public const MATERNITY_DAYS = 112;

    /** ছুটির প্রথম দিনে চাকরির ন্যূনতম বয়স, মাসে */
    public const MATERNITY_SERVICE_MONTHS = 6;

    public function isMaternity(): bool
    {
        return $this->code === self::MATERNITY;
    }

    /**
     * ⓘ দিন গোনা ক্যালেন্ডারে — সাপ্তাহিক ছুটি আর তালিকার ছুটিও ছুটির দিন। মাতৃত্বকালীন ছুটি আইনে "সপ্তাহ" ধরে (ধারা ৪৬), কাজের
     * দিন নয়: ১৬ সপ্তাহের ছুটিতে শুক্রবার বাদ দিলে ১১২ দিনের কোটা ৯৬ দিনেই ফুরাত না, আর আবার চাওয়া যেত।
     */
    public function countsCalendarDays(): bool
    {
        return $this->isMaternity();
    }

    /** সীমাহীন ছুটি — বিনা বেতনের ছুটিতে বছরের কোটা থাকে না। ⛔ অর্জিত ছুটি কখনো সীমাহীন নয় — সীমা অর্জনটাই। */
    public function hasNoYearlyLimit(): bool
    {
        return ! $this->isEarned() && bccomp((string) $this->days_per_year, '0', 1) <= 0;
    }
}
