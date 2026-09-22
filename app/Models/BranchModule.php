<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * এই শাখা এই মডিউলটা পায় কি না।
 *
 * ── ⓘ কেন এটা কোরে, কোনো মডিউলের ভিতরে নয় ────────────────────────────
 * সারিটা বলে **কোন মডিউল কোথায় চলবে** — অর্থাৎ এটা কোনো একটা মডিউলের
 * সম্পত্তি নয়, সবগুলোর উপরের কথা। ⚠️ SystemAdmin-এ রাখলে কোর একটা
 * মডিউলের নাম জানত, আর ২১ সেপ্টেম্বরে ঠিক সেই শেষ তীরটাই মোছা হয়েছে
 * ([[App\Policies\UserPolicy]]-র মাথায় কারণ লেখা)।
 *
 * ── ⚠️ সারি না থাকা মানে "হ্যাঁ" ──────────────────────────────────────
 * ⛔ অনুপস্থিতিকে "বন্ধ" ধরলে টেবিলটা বানানোমাত্র প্রতিটা শাখার মেনু
 * খালি হয়ে যেত। ⓘ সারি বসে কেবল তখন, যখন কেউ ইচ্ছা করে একটা মডিউল ঐ
 * শাখায় **বন্ধ** করেন। [[MenuBuilder::moduleEnabled()]]-এ নিয়মটা লেখা।
 */
final class BranchModule extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'branch_id',
        'module',
        'is_enabled',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * ⭐ এই শাখায় যে মডিউলগুলো ইচ্ছা করে বন্ধ করা হয়েছে।
     *
     * ⓘ কেবল বন্ধগুলোই ফেরে, চালুগুলো নয় — কারণ চালু থাকাটাই ডিফল্ট,
     * আর একটা `is_enabled = true` সারি কেবল "কেউ একবার বন্ধ করে আবার
     * খুলেছিল" বলে। ⚠️ দুইটা মিলিয়ে গুনলে ঐ পুরনো সারিগুলো ভুল উত্তর
     * দিত।
     *
     * @return list<string>
     */
    public static function switchedOffIn(?int $branchId): array
    {
        if ($branchId === null) {
            return [];
        }

        return self::query()
            ->where('branch_id', $branchId)
            ->where('is_enabled', false)
            ->pluck('module')
            ->all();
    }
}
