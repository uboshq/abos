<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ "এটা ঠিক আছে" — একটা নজর কারণসহ কিছুদিন চুপ (পূর্ণ পরিকল্পনা অংশ ১১; SAP, Oracle-এর মতো)।
 *
 * ⓘ সংখ্যা বদলালে নজর আবার ওঠে না যতদিন না মেয়াদ ফুরায় — তাই মেয়াদ ছোট (৭ দিন), আর কারণ লেখা বাধ্যতামূলক।
 */
class AssistantDismissal extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** ⓘ কতদিন চুপ — এক সপ্তাহ; তারপর সমস্যা থাকলে আবার ওঠে */
    public const DAYS = 7;

    protected $fillable = ['company_id', 'finding', 'reason', 'until', 'user_id'];

    protected function casts(): array
    {
        return ['until' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
