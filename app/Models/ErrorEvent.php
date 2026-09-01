<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা ভুল, যা ব্যবস্থাটা নিজে থেকে লিখে রেখেছে।
 *
 * ── কেন `BelongsToCompany` ব্যবহার করা হয় না ─────────────────────────
 * ওই trait প্রসঙ্গ না পেলে ব্যতিক্রম ছোঁড়ে, আর সেটা এখানে ঠিক উল্টো
 * ফল দিত: ভুলটা যদি প্রসঙ্গ বসার আগেই ঘটে (লগইনের পর্দায়, বা
 * `ResolveCompanyContext` নিজে ভাঙলে), তবে সেটা লিখতে গিয়ে **আরেকটা
 * ব্যতিক্রম** হত — অর্থাৎ ঠিক যে ভুলগুলো সবচেয়ে জরুরি, সেগুলোই কখনো
 * লেখা যেত না।
 *
 * তাই `company_id` এখানে nullable, আর ছাঁকনিটা পর্দায় হাতে বসে
 * ([[ErrorLogController]])।
 */
class ErrorEvent extends Model
{
    use HasPublicId;

    /**
     * `created_at`/`updated_at` নেই — `first_seen_at`/`last_seen_at`ই সেগুলো।
     *
     * দুইটা রাখলে একই সত্যের দুইটা নাম হত, আর একদিন একটা বদলে অন্যটা
     * থাকত। আর এখানে নামটা গুরুত্বপূর্ণ: সারিটা কখন **তৈরি** হয়েছে তা
     * নয়, ভুলটা **প্রথম** আর **শেষ** কবে দেখা গেছে — সেটাই পড়ার জিনিস।
     */
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'user_id', 'fingerprint', 'class', 'message',
        'file', 'line', 'route', 'method', 'path', 'trace',
        'times', 'first_seen_at', 'last_seen_at',
        'acknowledged_at', 'acknowledged_by',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'times' => 'integer',
            'line' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** এখনো কেউ দেখেনি এমনগুলো। */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
    }

    /** নতুনটা আগে — এই পর্দায় পুরনো ভুল নিচে থাকাই স্বাভাবিক। */
    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('last_seen_at');
    }

    /**
     * ক্লাসের নামটা মানুষের পড়ার মতো করে — namespace ছাড়া।
     *
     * পুরো namespace দেখালে তালিকার প্রতিটা সারির অর্ধেক জায়গা
     * `Illuminate\Database\Eloquent\` জাতীয় লেখায় চলে যেত, আর যেটা
     * আলাদা করে চেনায় সেই শেষ শব্দটাই কাটা পড়ত।
     */
    public function shortClass(): string
    {
        $at = strrpos($this->class, '\\');

        return $at === false ? $this->class : substr($this->class, $at + 1);
    }

    /**
     * ফাইলের পথটাও ছোট করে — রিপোর ভিতর থেকে।
     *
     * সার্ভারের পুরো পথ (`/Users/mac/abos/app/...`) কোনো কাজে আসে না,
     * আর মেশিন বদলালে ওটা বিভ্রান্তিকরও হয়।
     */
    public function shortFile(): string
    {
        $file = (string) $this->file;

        foreach (['/app/', '/vendor/', '/database/', '/routes/'] as $mark) {
            $at = strpos($file, $mark);

            if ($at !== false) {
                return ltrim(substr($file, $at), '/');
            }
        }

        return $file;
    }

    /**
     * আঙুলের ছাপ — একই ভুল বারবার এলে একটাই সারি।
     *
     * শ্রেণি, ফাইল আর লাইন — তিনটাই লাগে। কেবল শ্রেণি ধরলে
     * `QueryException` নামের একটা সারিতে পাঁচটা আলাদা ভুল মিশে যেত।
     * বার্তা যোগ করা হয় না, কারণ ওখানে প্রায়ই আইডি থাকে
     * ("No query results for model [User] 47"), আর তখন প্রতিটা আইডি
     * একটা নতুন সারি বানাত — অর্থাৎ গুচ্ছ করার পুরো উদ্দেশ্যই ব্যর্থ।
     */
    public static function fingerprintFor(string $class, ?string $file, ?int $line): string
    {
        return sha1($class.'|'.((string) $file).'|'.((string) $line));
    }
}
