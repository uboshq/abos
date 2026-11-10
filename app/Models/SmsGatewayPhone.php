<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ⭐ SMS পাঠানোর ফোন — অফিসের Android ফোন, নিজের সিমে কোম্পানির SMS পাঠায় (মালিকের "ok", নমুনা ধাপ ২)।
 *
 * 🔒 চাবিটা একবারই দেখানো হয় ([[issue()]]); ডাটাবেজে কেবল sha256 — ডাটাবেজ কারও হাতে গেলেও চাবি দিয়ে ফোন সাজা যায় না।
 */
class SmsGatewayPhone extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** ⓘ এর বেশি সময় দেখা না গেলে "সংযুক্ত নয়" — ফোন প্রতি মিনিটে আসে */
    public const ONLINE_MINUTES = 5;

    protected $fillable = ['company_id', 'name', 'sim_number', 'token_hash', 'daily_limit', 'is_active', 'last_seen_at', 'app_version', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'daily_limit' => 'integer',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * ⓘ ফোন মিনিটে একবার আসে — "শেষ দেখা" আর অ্যাপের সংস্করণ যন্ত্রের হিসাব, অডিটে নয় (নইলে দিনে ১৪৪০টা সারি)।
     * ⛔ চাবির hash-ও নয় — নতুন চাবি দেওয়া "নতুন চাবি" কাজ হিসেবে আলাদা ওঠে।
     *
     * @return list<string>
     */
    public function auditIgnores(): array
    {
        return ['last_seen_at', 'app_version', 'token_hash'];
    }

    /** নতুন চাবি — সাদা লেখাটা ফেরত, সারিতে কেবল তার hash */
    public static function newToken(): array
    {
        $plain = 'smsgw_'.Str::random(48);

        return [$plain, hash('sha256', $plain)];
    }

    /** ⓘ চাবি দিয়ে ফোন — বন্ধ করা ফোন পাওয়া যায় না; কোম্পানির দেয়াল এখানে নেই, কারণ ফোনের কোনো লগইন নেই */
    public static function byToken(?string $plain): ?self
    {
        if ($plain === null || ! str_starts_with($plain, 'smsgw_') || strlen($plain) > 80) {
            return null;
        }

        return static::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $plain))->where('is_active', true)->first();
    }

    public function online(): bool
    {
        return $this->is_active && $this->last_seen_at instanceof Carbon && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_MINUTES));
    }

    /** আজ এই ফোন কয়টা পাঠাল — দিনের সীমার হিসাব */
    public function sentToday(): int
    {
        return SmsOutbox::query()->withoutGlobalScopes()->where('gateway_phone_id', $this->id)
            ->where('taken_at', '>=', now()->startOfDay())->whereIn('status', [SmsOutbox::TAKEN, SmsOutbox::SENT, SmsOutbox::FAILED])->count();
    }
}
