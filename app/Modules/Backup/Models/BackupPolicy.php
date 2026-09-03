<?php

declare(strict_types=1);

namespace App\Modules\Backup\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা নীতি — কত ঘন ঘন, কী নেওয়া হবে, কোথায় যাবে, কতদিন থাকবে।
 *
 * ── কেন একাধিক নীতি ──────────────────────────────────────────────────
 * একটা কোম্পানির একসাথে দুইটা আলাদা নিয়ম থাকা স্বাভাবিক:
 *
 *   "রোজ রাত ২টায় পূর্ণ ব্যাকআপ, ৩০ দিন রাখা, পেনড্রাইভ + ক্লাউডে"
 *   "মাসে একবার, সাত বছর রাখা, শুধু অফসাইটে"
 *
 * একটা সারিতে চাপালে দ্বিতীয়টার জন্য জায়গা থাকত না, আর মানুষ তখন
 * হাতে চালাতেন — যেটা কেউ মনে রাখে না।
 *
 * ── `frequency` cron নয়, আর সেটা ইচ্ছাকৃত ────────────────────────────
 * `0 2 * * *` লেখা একটা কারিগরি দক্ষতা, আর এটা **গ্রাহকের পর্দা**।
 * ABOS বিক্রি হয় এমন মানুষের কাছে যাঁরা ডেভেলপার নন। তাই চারটা শব্দ —
 * `hourly` · `daily` · `weekly` · `monthly` — আর একটা সময়ের ঘর।
 *
 * ⓘ ভবিষ্যতে কারও যদি "প্রতি ১৫ মিনিট" লাগে, সেটা `frequency`-তে
 * আরেকটা শব্দ যোগ করার কাজ — cron পার্সার লেখার নয়।
 */
class BackupPolicy extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'bak_policies';

    protected $fillable = [
        'company_id', 'name', 'frequency', 'run_at', 'backup_type', 'scope',
        'destinations', 'retention', 'encrypt', 'verify', 'test_restore',
        'notify_on', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'destinations' => 'array',
            'retention' => 'array',
            'encrypt' => 'boolean',
            'verify' => 'boolean',
            'test_restore' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class, 'policy_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এই নীতির গন্তব্যগুলো — সক্রিয়গুলোই কেবল।
     *
     * ⚠️ নিষ্ক্রিয় গন্তব্য চুপচাপ বাদ যায়, কিন্তু **নীতির তালিকা থেকে
     * মোছা হয় না**। কারণ নিষ্ক্রিয় করা প্রায়ই সাময়িক — একটা ড্রাইভ
     * বদলানো হচ্ছে, একটা টোকেন নবায়ন হচ্ছে। তালিকা থেকে ফেলে দিলে
     * ফিরে এসে কেউ মনে করতে পারতেন না ওটা কোন নীতির অংশ ছিল।
     */
    public function activeDestinations()
    {
        return BackupDestination::query()
            ->whereIn('id', $this->destinations ?? [])
            ->where('is_active', true)
            ->get();
    }

    /**
     * এই নীতিতে কি কোনো গন্তব্য আছে যা সার্ভারের বাইরে?
     *
     * ── কেন এই প্রশ্নটা আলাদা করে জিজ্ঞেস করা হয় ─────────────────────
     * ৩-২-১ নিয়মের গোটা কারণ এটাই। একটা নীতি রোজ ব্যাকআপ নিতে পারে,
     * যাচাই করতে পারে, সবুজ দেখাতে পারে — আর তবু **মেশিনটা পুড়লে
     * সবকিছু হারাতে পারে**, যদি প্রতিটা কপি ওই মেশিনেই থাকে।
     *
     * আজ লাইভে ঠিক সেই অবস্থা: ৭৩টা ব্যাকআপ, সবগুলো একই ডিস্কে।
     */
    public function hasACopyOffTheMachine(): bool
    {
        return BackupDestination::query()
            ->whereIn('id', $this->destinations ?? [])
            ->where('is_active', true)
            ->whereIn('kind', ['offsite', 'offline'])
            ->exists();
    }
}
