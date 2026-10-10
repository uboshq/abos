<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ কোম্পানির একটা পৌঁছানোর মাধ্যম — ইমেইল, Web Push, মোবাইল পুশ, SMS (মালিকের স্পেক §৭; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
 *
 * ⛔ গোপন চাবি (`credentials`) Laravel-এর encrypter দিয়ে এনক্রিপ্ট করা থাকে, আর মডেল থেকে বাইরে যায় না (`$hidden`);
 * নিরীক্ষায় কেবল "বদলেছে" লেখা হয়, মান নয় ([[auditIgnores()]])। সারি না থাকা মানে মাধ্যমের ডিফল্ট
 * ([[ChannelRegistry::enabled()]])।
 */
class NotificationChannel extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const EMAIL = 'email';

    public const WEB_PUSH = 'web_push';

    public const MOBILE_PUSH = 'mobile_push';

    public const SMS = 'sms';

    /** @var list<string> পর্দায় এই ক্রমে */
    public const ALL = [self::EMAIL, self::WEB_PUSH, self::MOBILE_PUSH, self::SMS];

    protected $fillable = [
        'company_id', 'channel', 'enabled', 'provider', 'sender_id', 'credentials',
        'last_checked_at', 'last_check_ok', 'last_error',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'last_checked_at' => 'datetime',
            'last_check_ok' => 'boolean',
        ];
    }

    /** @return list<string> ⛔ গোপন চাবির মান নিরীক্ষায় যায় না */
    public function auditIgnores(): array
    {
        return ['credentials', 'last_checked_at', 'last_check_ok', 'last_error'];
    }

    /** একটা গোপন চাবি — না থাকলে `null` */
    public function secret(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
