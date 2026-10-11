<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ একটা ব্রাউজারের Web Push সাবস্ক্রিপশন (মালিকের স্পেক §৭ "Permission, Subscription, Expiry, Revocation"; ধাপ ২)।
 *
 * ⛔ ব্রাউজারের চাবি দুইটা (`p256dh`, `auth`) এনক্রিপ্ট করা, আর মডেল থেকে বাইরে যায় না। প্রোভাইডার "আর নেই" (৪০৪/৪১০)
 * বললে সারিটা প্রত্যাহার হয় — আর পাঠানো হয় না।
 *
 * IsAudited নেই: ব্যক্তির নিজের ব্রাউজার, ব্যবসার তথ্য নয় — [[NotificationChoice]]-এর একই যুক্তি।
 */
class NotificationSubscription extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'user_id', 'endpoint', 'endpoint_hash', 'keys', 'user_agent', 'expires_at', 'revoked_at', 'last_used_at',
    ];

    protected $hidden = ['keys'];

    /**
     * ⛔ ব্রাউজারের পুশ-সেবার চেনা ঠিকানা — কেবল এগুলোতেই সার্ভার পুশ পাঠায়। ঠিকানাটা ব্রাউজার থেকে আসে, তাই খোলা রাখলে যে
     * কেউ সার্ভারকে দিয়ে যেকোনো ঠিকানায় অনুরোধ পাঠাতে পারতেন (SSRF)। Chrome/Edge (FCM), Firefox (Mozilla), পুরনো Edge
     * (Windows), Safari (Apple)।
     */
    public const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
    ];

    /** ⓘ এই ধরনের উপ-ঠিকানাও চলে — `*.notify.windows.com`, `*.push.apple.com`, `*.push.services.mozilla.com` */
    public const PUSH_HOST_SUFFIXES = ['.notify.windows.com', '.push.apple.com', '.push.services.mozilla.com'];

    /** ঠিকানাটা কি চেনা পুশ-সেবার, https-এ, আর কোনো পোর্ট বা লগইন ছাড়া */
    public static function knownEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($host, self::PUSH_HOSTS, true)
            || array_filter(self::PUSH_HOST_SUFFIXES, fn (string $suffix) => str_ends_with($host, $suffix)) !== [];
    }

    protected function casts(): array
    {
        return [
            'keys' => 'encrypted:array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
