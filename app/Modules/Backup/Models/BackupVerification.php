<?php

declare(strict_types=1);

namespace App\Modules\Backup\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা যাচাইয়ের ফল — "এই কপিটা সত্যিই ফেরে কি না"।
 *
 * ── কেন আলাদা টেবিল, রানের ভেতরে একটা কলাম নয় ────────────────────────
 * **"ব্যাকআপ নেওয়া হয়েছে" আর "ব্যাকআপ কাজ করে" — দুইটা আলাদা কথা**,
 * আর দ্বিতীয়টা প্রমাণ করতে হয়।
 *
 * একটা রানের তিন ধরনের যাচাই হয়, তিনটা ভিন্ন খরচে:
 *
 *   checksum       sha256 মিলিয়ে দেখা — মুহূর্তের কাজ
 *   integrity      gzip খুলে দেখা — সেকেন্ডের কাজ
 *   test_restore   সত্যিই একটা ডাটাবেসে ফিরিয়ে আনা — মিনিটের কাজ
 *
 * তিনটা ভিন্ন সময়েও চলতে পারে: checksum সাথে সাথে, test_restore হয়তো
 * রাতে একবার। একটা কলামে চাপালে **"শেষ কবে সত্যিকারের restore পরীক্ষা
 * হয়েছিল"** প্রশ্নের উত্তর দেওয়া যেত না — আর ব্লুপ্রিন্টের "০ error"
 * শর্তটার পুরো মানেই ওই প্রশ্নে।
 *
 * ── `detail` কেন লাগে ────────────────────────────────────────────────
 * ⚠️ **"সফল" লেখা একটা সারি প্রমাণ নয়; সংখ্যাটাই প্রমাণ।**
 *
 * ৩ সেপ্টেম্বর ২০২৬-এ এই কথাটা হাতে-কলমে শেখা হয়েছে: একটা restore
 * যাচাই "০ বনাম ০" মিলিয়ে সবুজ দেখিয়েছিল — একটাও কোয়েরি চলেনি,
 * কিন্তু দুই দিকই খালি বলে তুলনাটা মিলে গিয়েছিল।
 *
 * তাই এখানে কেবল `passed` লেখা হয় না, **কী পাওয়া গেল সেটাও** —
 * কয়টা টেবিল, কয়টা সারি, কোন হ্যাশ। যে যাচাই সংখ্যা দেখাতে পারে না,
 * সে যাচাই নয়।
 */
class BackupVerification extends Model
{
    /*
     * ⚠️ বাকি তিনটা ব্যাকআপ-মডেলে ছিল, এটায় বাদ পড়েছিল — `PublicIdTest`
     * ডিপ্লয়ের ঠিক আগে ধরেছে (৩ সেপ্টেম্বর ২০২৬)। এই রিপোতে ঠিকানায়
     * ডাটাবেসের `id` কখনো যায় না, তাই প্রতিটা মডেলেই এটা লাগে।
     */
    use HasPublicId;

    protected $table = 'bak_verifications';

    /* ⚠️ `created_at`/`updated_at` নেই — `verified_at`ই সময়। দুইটা
       রাখলে একদিন কেউ ভুলটা ধরত: রেকর্ডটা কখন লেখা হলো আর যাচাইটা
       কখন হলো, দুইটা এক নয়। */
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'kind', 'status', 'detail', 'duration_ms', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'duration_ms' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'run_id');
    }

    /**
     * এই যাচাইটা কি সত্যিই কিছু দেখেছে?
     *
     * ⚠️ `status === 'passed'` যথেষ্ট নয় — উপরের মন্তব্যের ফাঁদটা ঠিক
     * এখানেই বসে। একটা যাচাই শূন্য টেবিল আর শূন্য সারি নিয়েও "মিলে
     * গেছে" বলতে পারে, আর সেটা সবুজ দেখায়।
     *
     * তাই গার্ডগুলো এই পদ্ধতিটা ডাকবে, কাঁচা `status` নয়।
     */
    public function sawSomething(): bool
    {
        if ($this->status !== 'passed') {
            return false;
        }

        $detail = $this->detail ?? [];

        return ($detail['tables'] ?? 0) > 0 || ($detail['rows'] ?? 0) > 0;
    }
}
