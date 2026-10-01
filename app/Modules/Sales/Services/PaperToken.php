<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\DeliveryChallan;
use App\Core\Support\DocumentStatus;

/**
 * কাগজের QR-এর সই-করা টোকেন — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬: *"এক কাগজে এক QR, সব কাজ"*।
 *
 * ── ⭐ কী থাকে, কী থাকে না ──────────────────────────────────────────────
 * টোকেন = চালানের `public_id` (১৬ বাইট, base64url) + ১২ বাইটের HMAC। কোনো টাকা, নাম, দোকান বা
 * গ্রাহক নেই — কাগজটা যার হাতেই যাক, QR পড়ে কিছু জানা যায় না। দেখতে লগইন আর চাবি লাগে।
 *
 * ── ⭐ চাবি ─────────────────────────────────────────────────────────────
 * HMAC-এর চাবি APP_KEY থেকে আলাদা করে বের করা (`abos.paper-qr.v1` লেবেলে) — কাঁচা APP_KEY নয়, তাই
 * লেবেলের সংস্করণ বদলালেই সব পুরনো QR একসাথে বাতিল করা যায়, অ্যাপের বাকি কিছু না ছুঁয়ে।
 * সইয়ে কোম্পানি আর কাগজের ধরনও ঢোকে — অন্য কোম্পানির চালানের টোকেন এখানে মেলে না।
 *
 * ⓘ টেবিল নেই (সমন্বয়ক, ১ অক্টোবর): চালান বাতিল হলেই টোকেন মরে ([[resolve()]] দেখে), আর
 * স্ক্যানের খাতা সাধারণ নিরীক্ষায়।
 */
final class PaperToken
{
    private const LABEL = 'abos.paper-qr.v1';

    private const TYPE = 'challan';

    public function for(DeliveryChallan $challan): string
    {
        $raw = hex2bin(str_replace('-', '', (string) $challan->public_id));

        return $this->b64($raw).$this->b64($this->sign((int) $challan->company_id, (string) $challan->public_id));
    }

    /**
     * টোকেন থেকে চালানের `public_id` — সই মিললে আর চালান বাতিল না হলে; নইলে `null`।
     *
     * ⚠️ এখানে কোম্পানির দেয়াল ছাড়া খোঁজা হয়, কেবল সই যাচাইয়ের জন্য (কোন কোম্পানির নামে সই)।
     * কাগজটা দেখানো হয় তারপর সাধারণ দেয়ালের ভেতর দিয়ে — অন্য কোম্পানি বা শাখা হলে সেখানে ৪০৪।
     */
    public function resolve(string $token): ?string
    {
        if (strlen($token) !== 38 || ! preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }

        $raw = $this->unb64(substr($token, 0, 22));
        $sig = $this->unb64(substr($token, 22));

        if ($raw === null || $sig === null || strlen($raw) !== 16) {
            return null;
        }

        $hex = bin2hex($raw);
        $publicId = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));

        $row = DeliveryChallan::query()->withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->first(['id', 'company_id', 'public_id', 'status']);

        if ($row === null || ! hash_equals($this->sign((int) $row->company_id, $publicId), $sig)) {
            return null;
        }

        // ⛔ বাতিল কাগজের QR মৃত — ছাপা কাগজ কারো হাতে থেকে গেলেও
        if ((string) $row->status === DocumentStatus::CANCELLED) {
            return null;
        }

        return $publicId;
    }

    private function sign(int $companyId, string $publicId): string
    {
        $key = hash_hmac('sha256', self::LABEL, (string) config('app.key'), true);

        return substr(hash_hmac('sha256', $companyId.'|'.self::TYPE.'|'.$publicId, $key, true), 0, 12);
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function unb64(string $text): ?string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
