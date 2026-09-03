<?php

declare(strict_types=1);

namespace App\Modules\Backup\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * একবার ব্যাকআপ নেওয়ার হিসাব — কী হলো, কোথায় গেল, কতটা গেল।
 *
 * ── কেন `status` একটা শব্দে বলা যায় না ────────────────────────────────
 * একটা ব্যাকআপ পাঁচ জায়গায় যেতে পারে, আর **তিনটায় গিয়ে দুইটায় ব্যর্থ
 * হওয়াটাই সবচেয়ে সাধারণ ফল** — পেনড্রাইভ খোলা, নেট বন্ধ, ক্লাউডের
 * টোকেনের মেয়াদ শেষ।
 *
 * ওই অবস্থাটাকে "সফল" বললে মিথ্যা, "ব্যর্থ" বললেও মিথ্যা — আর দুইটাই
 * মানুষকে ভুল কাজ করায়: প্রথমটায় কেউ খোঁজ নেয় না, দ্বিতীয়টায় কেউ
 * আবার চালাতে গিয়ে যেগুলো গেছে সেগুলোও আবার পাঠায়।
 *
 * তাই `status` কেবল **সামগ্রিক রায়**, আর সত্যিটা থাকে
 * `destinations_ok` / `destinations_failed`-এ, গন্তব্য ধরে ধরে।
 */
class BackupRun extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    /*
     * ⚠️ `IsAudited` নেই, আর সেটা ইচ্ছাকৃত।
     *
     * এই টেবিলটা **নিজেই একটা লগ** — প্রতিটা সারি একটা ঘটনার রেকর্ড,
     * আর সারিগুলো তৈরি হওয়ার পর বদলায় না (কেবল `finished_at` আর
     * ফলটা বসে, একই লেনদেনে)। অডিট বসালে প্রতিটা ব্যাকআপে দুইটা করে
     * সারি লেখা হত — একটা এখানে, একটা `audit_trails`-এ — আর দ্বিতীয়টা
     * প্রথমটার চেয়ে কম বলত।
     *
     * ⚠️ কিন্তু **restore আর গন্তব্য সাজানো অডিটে যাবেই** — ওগুলো
     * মানুষের সিদ্ধান্ত, আর ধ্বংসাত্মক। দেখুন [[BackupDestination]]
     * (`IsAudited` আছে) আর restore-এর পথ।
     */

    protected $table = 'bak_runs';

    protected $fillable = [
        'company_id', 'policy_id', 'started_at', 'finished_at', 'status',
        'backup_type', 'scope', 'file', 'bytes', 'checksum',
        'destinations_ok', 'destinations_failed', 'error',
        'triggered_by', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'destinations_ok' => 'array',
            'destinations_failed' => 'array',
            'bytes' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(BackupPolicy::class, 'policy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(BackupVerification::class, 'run_id');
    }

    /**
     * এই কপিটা কি সত্যিই ফিরিয়ে আনা গেছে?
     *
     * ⚠️ "ব্যাকআপ নেওয়া হয়েছে" আর "ব্যাকআপ কাজ করে" — দুইটা আলাদা কথা,
     * আর দ্বিতীয়টার উত্তর `status` দেয় না, যাচাইয়ের সারিগুলো দেয়।
     */
    public function restoreWasTested(): bool
    {
        return $this->verifications()
            ->where('kind', 'test_restore')
            ->where('status', 'passed')
            ->exists();
    }

    /**
     * কয়টা গন্তব্যে সত্যিই পৌঁছেছে।
     *
     * ⚠️ শূন্য মানে **ফাইলটা কেবল সার্ভারেই আছে** — অর্থাৎ ৩-২-১-এর
     * একটা শর্তও পূরণ হয়নি, আর মেশিনটা গেলে ব্যাকআপও যাবে। পর্দায়
     * এই সংখ্যাটাই সবচেয়ে জরুরি, "সফল" শব্দটা নয়।
     */
    public function copiesLanded(): int
    {
        return count($this->destinations_ok ?? []);
    }
}
