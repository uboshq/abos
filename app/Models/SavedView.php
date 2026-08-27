<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * একটা সংরক্ষিত দৃশ্য — কোনো তালিকার পর্দার ছাঁকনির সেট, নাম দিয়ে রাখা।
 *
 * ── কেন এটা অডিট করা হয় না ──────────────────────────────────────────
 * বাকি প্রায় সব মডেলে `IsAudited` আছে, কারণ ওগুলো ব্যবসার তথ্য — কে
 * কখন কী বদলেছে সেটা জানতেই হয়।
 *
 * দৃশ্য ব্যবসার তথ্য নয়, **ব্যক্তিগত সুবিধা**। কেউ তাঁর নিজের একটা
 * ছাঁকনির নাম বদলালে সেটা বইয়ের কিছু বদলায় না, আর ওটা অডিটে রাখলে
 * আসল বদলগুলো খুঁজে পাওয়া কঠিন হত।
 *
 * ── `HasPublicId` আছে, যদিও দৃশ্য বাইরে যায় না ──────────────────────
 * প্রথমে এটা বাদ দেওয়া হয়েছিল — দৃশ্যগুলো কেবল নিজের, কোনো API-তে
 * বেরোয় না, আর সবসময় নিজের সারিগুলোর মধ্যেই খোঁজা হয় (`mine()`)।
 *
 * [[PublicIdTest]] তাতে লাল হলো। ওর ছাড়ের তালিকায় কেবল ফ্রেমওয়ার্কের
 * টেবিল, আর সেই তালিকা বাড়তে দিলে একদিন পাহারাটাই অর্থহীন হত। কলামটা
 * সস্তা, ব্যতিক্রমটা দামি — তাই কলামটাই।
 */
class SavedView extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $table = 'ui_saved_views';

    protected $fillable = [
        'user_id', 'company_id', 'screen', 'name', 'query', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * এই ব্যবহারকারীর, এই পর্দার দৃশ্যগুলো।
     *
     * কোম্পানির ছাঁকনিটা `BelongsToCompany`-র গ্লোবাল স্কোপ থেকেই আসে,
     * তাই এখানে আবার লেখা হয়নি — দুইবার লিখলে একদিন একটা বদলাত আর
     * অন্যটা থেকে যেত।
     */
    public function scopeMine(Builder $query, int $userId, string $screen): Builder
    {
        return $query->where('user_id', $userId)
            ->where('screen', $screen)
            ->orderBy('name');
    }

    /**
     * এটাকে এই পর্দার ডিফল্ট বানানো, আর বাকিদের নামানো।
     *
     * ── কেন এটা মডেলে, কন্ট্রোলারে নয় ────────────────────────────────
     * নিয়মটা হলো "প্রতি ব্যবহারকারী প্রতি পর্দায় একটাই ডিফল্ট"। ডাটাবেস
     * ওটা বাঁধতে পারত একটা আংশিক ইউনিক সূচক দিয়ে, কিন্তু MySQL আংশিক
     * সূচক বোঝে না। নিয়মটা তাই কোডে, আর কোডে থাকলে **এক জায়গায়**
     * থাকা দরকার — নাহলে দ্বিতীয় কোনো পথে বসানো ডিফল্ট নিয়মটা ভাঙত।
     *
     * ── কেন লেনদেনের ভেতরে ───────────────────────────────────────────
     * দুইটা লেখার মাঝে ব্যর্থ হলে ব্যবহারকারীর কোনো ডিফল্টই থাকত না —
     * পুরনোটা নামানো হয়ে গেছে, নতুনটা ওঠেনি। তখন পর্দাটা খুলত সব সারি
     * নিয়ে, আর কেউ বুঝতেন না কেন তাঁর দৃশ্যটা "হারিয়ে গেল"।
     */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('user_id', $this->user_id)
                ->where('screen', $this->screen)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    /** দৃশ্যটার নিজের ঠিকানা — পর্দার পথ, তার সাথে সংরক্ষিত কোয়েরি। */
    public function url(): string
    {
        $base = route($this->screen);

        return $this->query === '' ? $base : $base.'?'.$this->query;
    }
}
