<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * রুটের সাপ্তাহিক ছকের একটা ঘর — কোন বারে কে এই রুটে যান, কবে থেকে কবে পর্যন্ত।
 *
 * ── কেন অডিটেড, আর কেন মোছা হয় না ────────────────────────────────────
 * হাতবদলের ইতিহাস থাকে (মালিকের উত্তর ৫, ২৬ সেপ্টেম্বর) — "মার্চে কে
 * যেতেন" প্রশ্নের উত্তর এই সারিগুলোই। তাই শেষ হলে `effective_to` বসে,
 * সারি থাকে; আর কে কবে বদলাল তা অডিটে।
 */
class RouteVisit extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_route_visits';

    /**
     * পর্দার ক্রম — শনিবার থেকে, কারণ এদেশে সপ্তাহ শনিবারে শুরু।
     * মানগুলো Carbon-এর (`dayOfWeek`: ০ = রবিবার)।
     *
     * @var list<int>
     */
    public const WEEK = [6, 0, 1, 2, 3, 4, 5];

    protected $fillable = [
        'company_id', 'route_id', 'user_id', 'weekday',
        'effective_from', 'effective_to', 'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'route_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** এই তারিখে যে ঘরগুলো চালু — শুরু হয়েছে, আর এখনো শেষ হয়নি। */
    public function scopeActiveOn(Builder $query, Carbon|string $date): Builder
    {
        $day = Carbon::parse($date)->toDateString();

        return $query->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $day));
    }

    public function isActiveOn(Carbon|string $date): bool
    {
        $day = Carbon::parse($date)->startOfDay();

        return $this->effective_from->lte($day)
            && ($this->effective_to === null || $this->effective_to->gte($day));
    }
}
