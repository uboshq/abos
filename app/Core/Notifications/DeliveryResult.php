<?php

declare(strict_types=1);

namespace App\Core\Notifications;

/**
 * ⭐ একটা পৌঁছানোর চেষ্টার ফল — পৌঁছেছে, সাময়িক ভুল (আবার চেষ্টা), না স্থায়ী ভুল (আর নয়) (মালিকের স্পেক §১৪)।
 *
 * ⓘ "স্থায়ী ভুলে অন্ধ চেষ্টা নয়": ঠিকানা নেই, সাবস্ক্রিপশন আর নেই, মাধ্যম সংযুক্ত নয় — এগুলো আবার চেষ্টায় সারে না।
 * ⛔ ভুলের লেখা সবসময় [[clean()]] দিয়ে ছাঁকা — টোকেন, চাবি বা ঠিকানার প্রশ্নাংশ খাতায় যায় না (স্পেক §১৩)।
 */
final class DeliveryResult
{
    public const SENT = 'sent';

    public const TRANSIENT = 'transient';

    public const PERMANENT = 'permanent';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $providerRef = null,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
    ) {}

    public static function sent(?string $providerRef = null): self
    {
        return new self(self::SENT, $providerRef === null ? null : mb_substr($providerRef, 0, 191));
    }

    /** আবার চেষ্টায় সারতে পারে — নেটওয়ার্ক, প্রোভাইডারের ব্যস্ততা (৪২৯, ৫xx); `$retryAfter` সেকেন্ডে, প্রোভাইডার বললে */
    public static function transient(string $error, ?int $retryAfter = null): self
    {
        return new self(self::TRANSIENT, null, self::clean($error), $retryAfter);
    }

    /** আবার চেষ্টায় সারবে না — আর চেষ্টা নয় */
    public static function permanent(string $error): self
    {
        return new self(self::PERMANENT, null, self::clean($error));
    }

    public function ok(): bool
    {
        return $this->outcome === self::SENT;
    }

    /**
     * ⛔ ভুলের লেখা থেকে গোপন জিনিস সরানো — ঠিকানার প্রশ্নাংশ (`?token=…`), লম্বা চাবির মতো শব্দ, ইমেইল ঠিকানা।
     */
    public static function clean(string $error): string
    {
        $error = preg_replace('/\?[^\s"\']*/', '?…', $error) ?? $error;
        $error = preg_replace('/[A-Za-z0-9_\-\.=+\/]{24,}/', '••••', $error) ?? $error;
        $error = preg_replace('/[^\s@<>"\']+@[^\s@<>"\']+/', '•@•', $error) ?? $error;

        return mb_substr(trim($error), 0, 255);
    }

    /**
     * ⛔ প্রোভাইডারের ব্যতিক্রম ভুলের খাতায় — কিন্তু ছাঁটা লেখায় (ধাপ ৫)। লাইব্রেরির ভুলে প্রায়ই ঠিকানা, পাসওয়ার্ড-ভরা URL বা
     * টোকেন থাকে; কাঁচা ব্যতিক্রম পাঠালে সেটা `error_events`-এ উঠত। এখানে কেবল ধরনের নাম আর [[clean()]] করা লেখা।
     */
    public static function report(\Throwable $e, string $where): void
    {
        report(new \RuntimeException($where.': '.class_basename($e).': '.self::clean($e->getMessage())));
    }
}
