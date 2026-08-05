<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা মুদ্রা — টাকা, ডলার, ইউরো।
 *
 * ডিফল্ট মুদ্রাটাই কোম্পানির ভিত্তি মুদ্রা: খতিয়ানের প্রতিটা অঙ্ক ওই
 * মুদ্রায় লেখা, আর বাকি সবার হার ওটার সাপেক্ষে।
 *
 * হার এখানে নেই, আলাদা সারিতে — কারণ হার একটা তারিখের সাথে বাঁধা।
 * এখানে একটা কলাম রাখলে গতকালের হার আজ হারিয়ে যেত।
 */
class Currency extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_currencies';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'symbol', 'decimal_places',
        'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ExchangeRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class)->orderByDesc('effective_from');
    }

    /**
     * এই দিনে এক একক এই মুদ্রার দাম, ভিত্তি মুদ্রায়।
     *
     * ── কেন "এই তারিখ বা তার আগের সর্বশেষ" ──────────────────────────
     * হার রোজ বসানো হয় না। ৫ তারিখে হার বসিয়ে ৯ তারিখে বিল কাটলে ৯
     * তারিখের কোনো সারি নেই — কিন্তু হার তো ৫ তারিখেরটাই চলছে। ঠিক
     * মিল খুঁজলে ওই বিলটা হার-নেই বলে আটকে যেত।
     *
     * ভিত্তি মুদ্রার নিজের হার সবসময় ১ — তার জন্য সারি বসাতে হয় না,
     * আর কেউ ভুলে ১.০২ বসিয়ে দিলে পুরো বই কাত হয়ে যেত।
     */
    public function rateOn(?Carbon $date = null): ?string
    {
        if ($this->is_default) {
            return '1.000000';
        }

        $rate = $this->rates()
            ->whereDate('effective_from', '<=', ($date ?? now())->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        return $rate === null ? null : (string) $rate->rate;
    }

    /**
     * এই মুদ্রার একটা অঙ্ক ভিত্তি মুদ্রায়।
     *
     * হার না জানা থাকলে শূন্য ফেরানো হয় না — শূন্য একটা বৈধ অঙ্কের
     * মতো দেখায়, আর তখন হার-বসাতে-ভুলে-যাওয়া বিলটা নীরবে শূন্য টাকার
     * বিল হয়ে বইয়ে বসে যেত।
     */
    public function toBase(string $amount, ?Carbon $date = null): ?string
    {
        $rate = $this->rateOn($date);

        return $rate === null ? null : bcmul($amount, $rate, 4);
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'currency';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.currency.rates', ['id' => $this->id]];
    }
}
