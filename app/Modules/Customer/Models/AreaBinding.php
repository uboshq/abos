<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

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
 * ⭐ চলমান এলাকা — এলাকা আর তার নিচের এলাকার সব ডিলার, পরে আসা ডিলারও (মালিক, ১২ নভেম্বর ২০২৬)।
 *
 * ⓘ ডিলার-বাঁধনের ([[DealerBinding]]) একই দিনের নিয়ম: শুরু আজ বা আগে, শেষ ফাঁকা বা আজ বা পরে; হাতবদলে মোছা নয়।
 */
class AreaBinding extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'area_bindings';

    protected $fillable = [
        'company_id', 'user_id', 'location_id', 'starts_on', 'ends_on', 'created_by', 'ended_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** আজ চালু। */
    public function scopeCurrent(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->where('starts_on', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
    }

    /** এখনো শেষ হয়নি — আজ চালু, অথবা ভবিষ্যতে শুরু। */
    public function scopeOpen(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
    }
}
