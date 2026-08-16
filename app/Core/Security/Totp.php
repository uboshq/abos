<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * সময়-ভিত্তিক এককালীন কোড — RFC 6238, নিজেদের হাতে।
 *
 * ── কেন কোনো প্যাকেজ নয় ─────────────────────────────────────────────
 * পুরো জিনিসটা HMAC-SHA1 আর base32 — PHP-তে দুইটাই আছে। একটা প্যাকেজ
 * টানা মানে বছরের পর বছর তার নিরাপত্তা-হালনাগাদ পাহারা দেওয়া, আর
 * কোডটা এতটুকু যে সেটার দাম বেশি।
 *
 * ── কেন কোনো বাইরের সার্ভিস নয় ──────────────────────────────────────
 * SMS বা ইমেইলে কোড পাঠানো মানে টাকা, আর মালিকের নিয়ম "paid sarvis
 * bad"। তার চেয়েও বড় কথা: বাংলাদেশে SMS দেরিতে আসে বা আসেই না, আর
 * তখন কেউ নিজের ব্যবস্থায় ঢুকতে পারেন না। Google Authenticator ধাঁচের
 * অ্যাপ ইন্টারনেট ছাড়াই কোড বানায় — ডিপোতে নেট গেলেও লগইন চলে।
 *
 * ── ঘড়ির পার্থক্য ───────────────────────────────────────────────────
 * ফোনের ঘড়ি আর সার্ভারের ঘড়ি কখনো হুবহু মেলে না। এক ধাপ আগে-পরে
 * মেনে নেওয়া হয় (±৩০ সেকেন্ড), কারণ না নিলে প্রতি মিনিটে কয়েকজনের
 * কোড "ভুল" দেখাত আর তাঁরা MFA বন্ধ করে দিতেন।
 *
 * বেশি ধাপ মেনে নেওয়াও চলে না: প্রতিটা বাড়তি ধাপ চুরি করা একটা কোডের
 * আয়ু বাড়ায়।
 */
final class Totp
{
    /** ধাপের দৈর্ঘ্য — RFC-র ডিফল্ট, আর প্রতিটা অ্যাপ এটাই ধরে। */
    private const PERIOD = 30;

    /** কয় অঙ্কের কোড — ছয়, কারণ মানুষ ছয়টা অঙ্কই টাইপ করতে পারে। */
    private const DIGITS = 6;

    /** আগে-পরে কয় ধাপ মেনে নেওয়া হয় — ঘড়ির পার্থক্যের জন্য। */
    private const DRIFT = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * নতুন একটা গোপন চাবি — base32-এ, ৩২ অক্ষর (১৬০ বিট)।
     *
     * `random_bytes` — `rand()` নয়। দ্বিতীয়টা অনুমান করা যায়, আর
     * অনুমানযোগ্য চাবি মানে MFA না থাকার সমান, শুধু সবাই ভাবে আছে।
     */
    public static function newSecret(): string
    {
        $bytes = random_bytes(20);
        $secret = '';

        foreach (str_split($bytes) as $byte) {
            // প্রতিটা বাইট থেকে দুইটা base32 অক্ষর — সহজ ও যথেষ্ট
            $secret .= self::ALPHABET[ord($byte) >> 3];
            $secret .= self::ALPHABET[(ord($byte) & 0x07) << 2 | random_int(0, 3)];
        }

        return $secret;
    }

    /**
     * এই মুহূর্তের কোড।
     *
     * পরীক্ষার জন্য `$at` দেওয়া যায়; বাস্তবে কখনো দেওয়া হয় না।
     */
    public static function codeFor(string $secret, ?int $at = null): string
    {
        return self::codeAtStep($secret, intdiv($at ?? time(), self::PERIOD));
    }

    /**
     * কোডটা ঠিক কি না।
     *
     * ── কেন `hash_equals` ───────────────────────────────────────────
     * সাধারণ `===` অক্ষর ধরে ধরে মেলায় আর প্রথম অমিলেই থেমে যায়।
     * সময় মেপে আক্রমণকারী তখন এক অঙ্ক করে কোডটা বের করে ফেলতে পারে।
     * `hash_equals` সবসময় একই সময় নেয়।
     */
    public static function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $step = intdiv($at ?? time(), self::PERIOD);

        for ($i = -self::DRIFT; $i <= self::DRIFT; $i++) {
            if (hash_equals(self::codeAtStep($secret, $step + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * অ্যাপে বসানোর ঠিকানা — `otpauth://totp/...`।
     *
     * ── কেন QR নয় ───────────────────────────────────────────────────
     * QR আঁকতে একটা লাইব্রেরি লাগত। প্রতিটা অথেনটিকেটর অ্যাপে হাতে
     * চাবি বসানোর পথ আছে, আর চাবিটা চার-চার ভাগে দেখালে সেটা টাইপ
     * করাও কঠিন নয়। লাইব্রেরিটা পরে যোগ করা যাবে — এই ঠিকানাটাই
     * তখন QR-এ যাবে, কোড বদলাতে হবে না।
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    /** চাবিটা পড়ার মতো করে — চার অক্ষরের ভাগে। */
    public static function readable(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private static function codeAtStep(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);

        // ধাপটা ৮ বাইটের big-endian — RFC-র নিয়ম
        $binary = hash_hmac('sha1', pack('N*', 0, $step), $key, true);

        // শেষ বাইটের নিচের চার বিট বলে কোথা থেকে চার বাইট নিতে হবে
        $offset = ord($binary[19]) & 0x0F;

        $number = (
            ((ord($binary[$offset]) & 0x7F) << 24)
            | (ord($binary[$offset + 1]) << 16)
            | (ord($binary[$offset + 2]) << 8)
            | ord($binary[$offset + 3])
        ) % (10 ** self::DIGITS);

        return str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper($secret)) as $char) {
            $index = strpos(self::ALPHABET, $char);

            // অচেনা অক্ষর চুপচাপ বাদ — মানুষ চাবিটা ফাঁকসহ কপি করেন
            if ($index !== false) {
                $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }

        $key = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr((int) bindec($byte));
            }
        }

        return $key;
    }
}
