<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ⭐ দিন বন্ধ — একটা দিনের খাতা গুনে-মিলিয়ে বন্ধ (মালিকের অনুমোদিত "দিন শেষের হিসাব", ১৬ ও ১৮ নভেম্বর ২০২৬)।
 *
 * ⓘ বন্ধ দিনের তারিখে নতুন বা বদলানো এন্ট্রি আটকায় [[OpenPeriod::assertOpen()]]; পেছনের তারিখের অনুমতি যাঁর আছে
 *   (`accounts.backdate.override`), তিনি পারেন।
 * ⓘ শাখা খালি = গোটা কোম্পানির দিন বন্ধ — তখন সব শাখার ঐ দিন বন্ধ। শাখার দিন বন্ধ কেবল ঐ শাখার।
 */
class DayClose extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_day_closes';

    protected $fillable = [
        'company_id', 'branch_id', 'day', 'cash_counted', 'cash_book', 'cash_gap', 'gap_reason', 'closed_by', 'closed_at',
        'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            // ⓘ টাকা কখনো float নয় (MoneyIsNeverAFloatTest)
            'cash_counted' => 'decimal:4',
            'cash_book' => 'decimal:4',
            'cash_gap' => 'decimal:4',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * এই দিন (এই শাখায়) বন্ধ কি না — কোম্পানির পুরো দিন বন্ধ হলেও হ্যাঁ।
     *
     * ⓘ শাখা না জানা গেলে (সব শাখার কাগজ) কেবল কোম্পানির পুরো দিনের বন্ধ দেখা হয়।
     * ⓘ আবার খোলা দিন বন্ধ নয় ([[recordOn()]] খোলা-বন্ধ দুইটাই দেয়)।
     */
    public static function closedOn(Carbon|string $day, ?int $branchId = null): ?self
    {
        $record = self::recordOn($day, $branchId);

        return $record !== null && $record->reopened_at === null ? $record : null;
    }

    /** এই দিনের বন্ধের দাগ — খোলা থাকলেও (আবার বন্ধ করলে একই সারি নতুন করে বসে) */
    public static function recordOn(Carbon|string $day, ?int $branchId = null): ?self
    {
        $date = ($day instanceof Carbon ? $day : Carbon::parse($day))->toDateString();

        return self::query()
            ->where('company_id', CompanyContext::id())
            ->where('day', $date)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId !== null, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->orderBy('id')
            ->first();
    }
}
