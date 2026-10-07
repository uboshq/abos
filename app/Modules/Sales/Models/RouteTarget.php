<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা রুটের এক মাসের লক্ষ্যমাত্রা।
 *
 * ⓘ অডিটেড, [[SalesTarget]]-এর একই কারণে: মাস শেষে লক্ষ্য নামিয়ে দিলে
 * অর্জন হঠাৎ ১২০% দেখায়, আর কাগজে কোনো চিহ্ন থাকে না।
 */
class RouteTarget extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_route_targets';

    protected $fillable = ['company_id', 'route_id', 'month', 'amount', 'created_by'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'route_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
