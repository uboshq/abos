<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * একটা কাগজের গোপন লিংক — গ্রাহককে পাঠানোর জন্য।
 *
 * ── ⚠️ এই সারিটাই একমাত্র পাহারা ────────────────────────────────────
 * গ্রাহকের লগইন নেই, থাকার কথাও নয়। তাই লিংকটাই চাবি, আর চাবিটা
 * নিরাপদ রাখে তিনটা জিনিস: অনুমান করা যায় না এমন ৬৪ অক্ষর, একটা মাত্র
 * কাগজে সীমাবদ্ধতা, আর নিজে থেকে মরে যাওয়া (৩০ দিন)।
 *
 * ⛔ এই সারি দিয়ে সিস্টেমের আর কোথাও ঢোকা যায় না — না তালিকায়, না
 * অন্য গ্রাহকের কাগজে, না লগইনের পর্দায়। যে রুট আর প্যারামিটার এখানে
 * লেখা, কেবল সেটাই আঁকা হয়।
 */
class DocumentShare extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** ⓘ মালিকের সিদ্ধান্ত, ২০ সেপ্টেম্বর ২০২৬ — ৩০ দিন পরে লিংকটা মরে যায় */
    public const LIVES_DAYS = 30;

    protected $table = 'doc_shares';

    protected $fillable = [
        'company_id', 'branch_id', 'route_name', 'route_params',
        'document_type', 'document_id', 'document_no', 'paper',
        'token', 'expires_at', 'revoked_at', 'opened_count', 'last_opened_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'route_params' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_opened_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAlive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->revoked_at === null && $this->expires_at->greaterThan($now);
    }

    public function daysLeft(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return max(0, (int) $now->diffInDays($this->expires_at, false));
    }

    /**
     * ⚠️ খোঁজাটা **কোম্পানির ছাঁকনি ছাড়া** — লিংক খোলার সময় কেউ লগ-ইন
     * নেই, তাই কোনো কোম্পানির প্রসঙ্গও নেই। ⓘ প্রসঙ্গটা সারিটা পাওয়ার
     * **পরে** বসানো হয় ([[App\Core\Services\PaperTrail::open()]]), তার
     * নিজের `company_id` থেকে।
     */
    public static function byToken(string $token): ?self
    {
        return static::query()->withoutGlobalScopes()->where('token', $token)->first();
    }

    public function scopeAlive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', Carbon::now());
    }
}
