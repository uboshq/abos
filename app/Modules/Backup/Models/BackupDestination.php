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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা গন্তব্য — যেখানে ব্যাকআপের কপি যাবে।
 *
 * ── এটা একটা সারি, একটা enum নয় ──────────────────────────────────────
 * মালিকের ব্লুপ্রিন্টের শেষ লাইনটাই এই ক্লাসের কারণ: *"Google
 * Drive/OneDrive/Dropbox-কে backup-এর সমার্থক না ধরে Backup Destinations
 * হিসেবে রাখা।"* নতুন কোনো সেবা যোগ করতে তাই একটা driver ক্লাস লাগে —
 * টেবিল, মাইগ্রেশন বা এই মডেল ছুঁতে হয় না।
 *
 * আর ৩ সেপ্টেম্বর ২০২৬-এ মালিক যেটা যোগ করেছেন, সেটা এটাকে বাধ্যতামূলক
 * করে: **ABOS দুইভাবেই বিক্রি হবে** — আমাদের সার্ভারে, আর ক্রেতার নিজের
 * সার্ভারে। ক্রেতার ঘরে আমাদের কোনো মেশিন নেই, আমাদের কোনো অ্যাকাউন্টও
 * নেই। তাই ইঞ্জিন কোনো মেশিনের নাম জানে না; সে জানে `driver`, আর বাকিটা
 * গ্রাহক নিজে একটা পর্দা থেকে ভরেন।
 */
class BackupDestination extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'bak_destinations';

    protected $fillable = [
        'company_id', 'name', 'driver', 'config', 'kind',
        'is_active', 'created_by',
    ];

    /**
     * ⚠️ `config` **এনক্রিপ্টেড** — আর এটাই এই ক্লাসের সবচেয়ে জরুরি লাইন।
     *
     * এখানে বসে SFTP-র পাসওয়ার্ড, S3-এর secret key, ক্লাউডের রিফ্রেশ
     * টোকেন। আর **এই ডাটাবেসটাই ব্যাকআপে যায়** — অর্থাৎ সাদা চোখে
     * রাখলে প্রতিটা ডাম্পের ভেতরে গ্রাহকের প্রতিটা চাবি চলে যেত, আর
     * ডাম্পটা একবার হাতছাড়া হলে গন্তব্যগুলোও সাথে যেত।
     *
     * ⓘ এটা `hidden`-এও আছে: `toArray()` বা কোনো JSON উত্তরে যেন
     * ভুলেও না যায়। এনক্রিপ্টেড হলেও সাইফারটেক্সট বাইরে দেওয়ার কোনো
     * কারণ নেই।
     */
    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_ok_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এই গন্তব্যে শেষ কবে সত্যিই পৌঁছানো গেছে — কত দিন আগে।
     *
     * ⚠️ "পাওয়া যাচ্ছে না" নিজে থেকে ভুল নয়: পেনড্রাইভ খুলে রাখাই তো
     * উদ্দেশ্য, আর যে ডিস্ক লাগানো নেই সেটাই ransomware-এর একমাত্র
     * সত্যিকারের উত্তর।
     *
     * ভুল হলো **কতদিন ধরে পাওয়া যাচ্ছে না**। তাই পর্দা লাল দেখাবে
     * দিনের সংখ্যা ধরে, উপস্থিতি ধরে নয় — নাহলে ঠিকভাবে ব্যবহার করা
     * একটা অফলাইন ড্রাইভ চিরকাল লাল দেখাত, আর মানুষ লাল দেখা বন্ধ
     * করে দিতেন।
     */
    public function daysSinceLastCopy(): ?int
    {
        /*
         * ⚠️ `(int)` — Carbon 3-এ `diffInDays()` **float** ফেরত দেয়
         * (`৩.০০০০০৮৬…`), আর এই পদ্ধতির ঘোষিত ধরন `?int`।
         *
         * ── কীভাবে ধরা পড়ল ─────────────────────────────────────────
         * A3 ঠিক এই ভুলটাই [[SystemAdminDashboard::lastBackup()]]-এ
         * পেয়েছে, একটা ফেলনা ডাটাবেসে নতুন ইনস্টল বানিয়ে হেঁটে — আর
         * ওখানে ফল ছিল **গোটা পাতা ৫০০**।
         *
         * ওর বার্তা পড়ে নিজের কোডে খুঁজতে গিয়ে দেখলাম হুবহু একই লাইন
         * এখানেও লিখে ফেলেছি। ⚠️ আর এটা এই মেশিনে কোনোদিন ধরা পড়ত
         * না: `last_ok_at` তখনই ভরে যখন একটা গন্তব্যে সত্যিই কপি
         * গেছে, তার আগে `null` — অর্থাৎ **কেবল সেই গ্রাহকের কাছেই
         * ভাঙত যাঁর ব্যাকআপ সত্যিই কাজ করছে**।
         *
         * `floor` নয়, cast — দুইটাই এখানে এক, কারণ পার্থক্যটা সবসময়
         * ধনাত্মক। আর "৩ দিন আগে" বলতে গিয়ে ৩.৯-কে ৪ বলা ভুল হত।
         */
        return $this->last_ok_at === null
            ? null
            : (int) $this->last_ok_at->diffInDays(now());
    }
}
