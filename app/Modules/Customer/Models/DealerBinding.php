<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ⭐ একজন কর্মী আর একজন ডিলারের বাঁধন — কবে থেকে কবে (⛔১৬, ২ অক্টোবর ২০২৬)।
 *
 * ⓘ হাতবদলে সারি মোছা হয় না, `ends_on` পায় — *"মার্চে ডিলারটা কার ছিল"* প্রশ্নের উত্তর
 * থাকে, আর পুরনো জন শেষ দিনের পরদিন থেকে আর দেখেন না ([[DealerScope::dealerIds()]])।
 *
 * ⚠️ এই মডেল নিজে ডিলারের দেয়ালের বাইরে, ইচ্ছা করে: এটাই দেয়ালের উৎস, আর এর পর্দা
 * কেবল বাঁধার চাবিওয়ালার ([[DealerBindingController]])।
 */
class DealerBinding extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'dealer_bindings';

    protected $fillable = [
        'company_id', 'user_id', 'customer_id', 'starts_on', 'ends_on', 'created_by', 'ended_by',
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

    /** ⓘ দেয়াল ছাড়া — বাঁধার পর্দা অন্য কর্মীর ডিলারও দেখায় */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScope(Customer::DEALER_WALL);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** আজ চালু — শুরু আজ বা আগে, শেষ ফাঁকা বা আজ বা পরে। */
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
