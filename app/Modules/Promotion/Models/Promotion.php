<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা অফার — কী দেওয়া হবে, কাকে, আর কবে পর্যন্ত।
 *
 * ── ⚠️ কেন `status` ও `type` কখনো `fillable`-এ নয় ───────────────────
 * ⓘ দুইটাই অবস্থা, আর অবস্থা বদলায় নিজের দরজা দিয়ে —
 * [[PromotionLifecycle]]। ⛔ `fillable`-এ থাকলে একটা সাধারণ সম্পাদনার
 * অনুরোধেই কেউ খসড়া অফারকে সোজা `active` করে দিতে পারতেন, আর
 * অনুমোদনের গোটা ধাপটা (§১৪) নীরবে এড়ানো যেত।
 *
 * ⓘ ঠিক এই ভুলটা নোটিশে একবার করা হয়েছে আর মেপে শেখা হয়েছে: পাহারাটা
 * কাজ করেছিল, ভুল দরজাটা ব্যবহার করা হচ্ছিল।
 */
class Promotion extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $fillable = [
        'name_en', 'name_bn', 'summary', 'terms',
        'starts_on', 'ends_on', 'starts_at', 'ends_at',
        'priority', 'combines',
    ];

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'status' => PromotionStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'combines' => PromotionCombines::class,
            'priority' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** ⓘ কার জন্য, কোন পণ্যে — সারি না থাকা মানে *"সব"*। */
    public function scopes(): HasMany
    {
        return $this->hasMany(PromotionScope::class);
    }

    /** ⓘ কত কিনলে — স্ল্যাবের ধাপগুলো, ক্রমে। */
    public function conditions(): HasMany
    {
        return $this->hasMany(PromotionCondition::class)->orderBy('step_order');
    }

    /** ⓘ কী পাবেন — শর্তের সাথে জোড়া, বা শর্তহীন। */
    public function benefits(): HasMany
    {
        return $this->hasMany(PromotionBenefit::class);
    }

    /** চলতি ভাষায় নাম। */
    public function name(): string
    {
        /*
         * ⚠️ বাংলা নাম খালি হতে পারে — এই বাড়ির নিয়মে ওটা ঐচ্ছিক।
         *
         * ⛔ ফিরে আসার পথ না থাকলে বাংলায় দাঁড়ানো মানুষ তালিকায় একটা
         * **খালি ঘর** দেখতেন, আর কোন অফার কোনটা বোঝা যেত না।
         */
        if (app()->getLocale() === 'bn' && filled($this->name_bn)) {
            return $this->name_bn;
        }

        return $this->name_en;
    }

    /**
     * ⭐ এই দিনে অফারটা সত্যিই খাটে কি না।
     *
     * ── ⚠️ কেন দুইটা প্রশ্ন, একটা নয় ───────────────────────────────
     * ⓘ *"অবস্থা সক্রিয়"* আর *"তারিখের ভিতরে"* — দুইটা আলাদা সত্য।
     * ⛔ কেবল অবস্থা দেখলে মেয়াদ পেরোনো অফার খাটত যতক্ষণ না কেউ
     * `expired` লেখে, আর ঐ লেখাটা একটা নির্ধারিত কাজের উপর দাঁড়াত —
     * যে কাজ একদিন চলতে ভুলে যায়।
     *
     * ⭐ তাই তারিখটাই শেষ কথা, অবস্থাটা নয়।
     */
    public function isLiveOn(Carbon|string $moment): bool
    {
        if (! $this->status->isLive()) {
            return false;
        }

        $at = $moment instanceof Carbon ? $moment : Carbon::parse($moment);

        if ($at->toDateString() < $this->starts_on->toDateString()
            || $at->toDateString() > $this->ends_on->toDateString()) {
            return false;
        }

        /*
         * ⭐ সময়ের ঘর দুইটা **সত্যিই পড়া হয়** — আর এটা মেপে শেখা।
         *
         * ⛔ প্রথম খসড়ায় ঘর দুইটা রাখা হয়েছিল অথচ কেউ পড়ত না। ⚠️ ফল:
         * *"৩০ জুন সন্ধ্যা ৬টায় শেষ"* লেখা অফার রাত ১২টা পর্যন্ত চলত —
         * ছয় ঘণ্টার বাড়তি ছাড়, আর পর্দায় সব ঠিক দেখাত।
         *
         * ⓘ সময়টা কেবল **প্রথম ও শেষ দিনেই** খাটে: মাঝের দিনগুলো পুরোটাই
         * অফারের ভিতরে। ⚠️ প্রতিদিন সময় ধরে কাটলে *"রোজ ১০টা–২টা"* হয়ে
         * যেত, যা স্পেকের অন্য জিনিস (§৬ ধাপ ৩)।
         */
        if ($this->starts_at !== null && $at->toDateString() === $this->starts_on->toDateString()
            && $at->format('H:i:s') < $this->starts_at) {
            return false;
        }

        if ($this->ends_at !== null && $at->toDateString() === $this->ends_on->toDateString()
            && $at->format('H:i:s') > $this->ends_at) {
            return false;
        }

        return true;
    }

    /**
     * ⓘ উপরের নিয়মটাই SQL-এ — দুই জায়গায় দুই নিয়ম হতে দেওয়া যায় না।
     *
     * ⚠️ তারিখের তুলনা `whereDate()` দিয়ে নয়, সাধারণ `where` দিয়ে।
     * ⓘ কলাম দুইটা এমনিতেই `date`, আর `whereDate()` ওদের `DATE()`-এ
     * জড়ায় — তখন `prom_live_ix` সূচকটা বসে থাকে আর গোটা টেবিল পড়া হয়।
     */
    public function scopeLiveOn(Builder $query, Carbon|string $moment): Builder
    {
        $at = $moment instanceof Carbon ? $moment : Carbon::parse($moment);
        $date = $at->toDateString();
        $clock = $at->format('H:i:s');

        return $query
            ->where('status', PromotionStatus::ACTIVE->value)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)

            /* ⓘ প্রথম দিনে হলে ঘড়িটাও মিলতে হবে; অন্য দিনে প্রশ্নই ওঠে না */
            ->where(fn ($q) => $q->where('starts_on', '<', $date)
                ->orWhereNull('starts_at')
                ->orWhere('starts_at', '<=', $clock))

            ->where(fn ($q) => $q->where('ends_on', '>', $date)
                ->orWhereNull('ends_at')
                ->orWhere('ends_at', '>=', $clock));
    }

    /**
     * ⛔ মেয়াদ পেরিয়েছে, অথচ অবস্থা এখনো বলছে চলছে।
     *
     * ⓘ নিয়মটা একবারই লেখা — [[Promotion::scopeLapsed()]]। ⚠️ ড্যাশবোর্ড
     * আগে একই শর্ত হাতে SQL-এ আবার লিখেছিল, আর দুই কপি একদিন আলাদা হত।
     */
    public function hasLapsed(): bool
    {
        return $this->status === PromotionStatus::ACTIVE
            && $this->ends_on->toDateString() < Carbon::today()->toDateString();
    }

    /** ⓘ উপরের প্রশ্নটাই তালিকার জন্য। */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query
            ->where('status', PromotionStatus::ACTIVE->value)
            ->where('ends_on', '<', Carbon::today()->toDateString());
    }
}
