<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা ছুটির আবেদন।
 *
 * মঞ্জুর হলে দিনগুলো হাজিরার খাতায় "ছুটি" হয়ে বসে যায় — নাহলে ছুটিতে
 * থাকা লোকটাকে অনুপস্থিত দেখাত, আর বেতন থেকে কাটা যেত।
 */
class LeaveApplication extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use \App\Core\Concerns\NamesItsPartyInNotices; // ⭐ বিজ্ঞপ্তিতে পক্ষের নাম (গ্রাহক হলে · পয়েন্ট) — মালিক, ১৯ অক্টোবর ২০২৬
    use SharedAcrossCompaniesWhenAsked;
    use SoftDeletes;

    protected $table = 'hr_leave_applications';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id',
        'from_date', 'to_date', 'expected_delivery_date', 'actual_delivery_date', 'days', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            // ⭐ মাতৃত্বকালীন ছুটিতে প্রসবের সম্ভাব্য দিন (ধারা ৪৭; fe, ২১ অক্টোবর ২০২৬)
            'expected_delivery_date' => 'date',
            // ⭐ প্রসবের আসল দিন — সই পড়লে বসে (ধারা ৪৫; [[MaternityDeliveryService::sign()]])
            'actual_delivery_date' => 'date',
            'days' => 'decimal:1',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /**
     * ⭐ সইকারীর জন্য সতর্কবার্তা — মাতৃত্বকালীন ছুটিতে কর্মীর জীবিত সন্তানের সংখ্যা লেখা নেই ("তথ্য নেই"; ধারা ৪৬(৩); fe-র উত্তর ২,
     * ২১ অক্টোবর ২০২৬; a6)। ⓘ আটকায় না — ওয়েবের তালিকা আর ফোনের সারি দেখায়, যাতে সইকারী জেনে সই দেন। সংখ্যাটা নয়, কেবল যে লেখা
     * নেই — তাই পরিচয়ের চাবি ছাড়াও দেখানো চলে।
     */
    public function signerWarning(): ?string
    {
        if (! $this->isPending() || ! (bool) $this->leaveType?->isMaternity()) {
            return null;
        }

        $employee = $this->employee ?? Employee::query()->withoutGlobalScopes()->find($this->employee_id);

        return $employee !== null && $employee->surviving_children === null ? __('hr::message.maternity_children_unknown') : null;
    }
}
