<?php

declare(strict_types=1);

namespace App\Core\Licence;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * কাগজটা সত্যিই আমাদের সই করা কি না।
 *
 * ── ⭐ কেন ed25519, আর কেন সই — একটা পাসওয়ার্ড নয় ───────────────────
 * পাসওয়ার্ড বা "সিরিয়াল কী" মেলানো মানে **যাচাইয়ের নিয়মটাও কোডে
 * থাকে** — আর কোড ক্রেতার মেশিনে। ⛔ তখন যে কেউ নিয়মটা পড়ে নিজের কী
 * বানিয়ে নিতে পারতেন।
 *
 * ⓘ সইয়ে উল্টো: কোডে থাকে কেবল **পাবলিক** চাবি, যেটা দিয়ে যাচাই করা
 * যায় কিন্তু বানানো যায় না। ⚠️ গোপন চাবিটা কোথাও যায় না — রিপোতে নয়,
 * ক্রেতার সার্ভারে নয়, `.env`-এ নয়। ⭐ ওটা কেবল মালিকের কাছে থাকে,
 * আর ওটা হারালে নতুন লাইসেন্স ইস্যু করা যায় না (পুরনোগুলো চলতে থাকে)।
 *
 * ── ⚠️ এটা ভাঙা যায়, আর সেটা লুকানো হচ্ছে না ────────────────────────
 * কোড ক্রেতার মেশিনে, তাই যিনি চান তিনি এই যাচাইটাই সরিয়ে দিতে পারেন।
 * ⓘ লক্ষ্য "অসম্ভব করা" নয় — লক্ষ্য **দুর্ঘটনাক্রমে চলতে না দেওয়া**,
 * আর ভাঙাটাকে একটা সচেতন কাজ বানানো। ⭐ মালিকের ভাষায়: *সৎ তালা*।
 */
final class LicenceReader
{
    /**
     * ⓘ কাগজটা কোথায় — `storage/app`-এর ভিতরে, যাতে ব্যাকআপে যায় আর
     * ওয়েব থেকে সরাসরি পড়া না যায়।
     */
    public const PATH = 'licence.json';

    /**
     * ⓘ পড়ার ফল এই অনুরোধে মনে রাখা — মিডলওয়্যার প্রতিটা অনুরোধে
     * জিজ্ঞেস করে, আর ফাইল পড়া + সই যাচাই প্রতিবার করার মানে নেই।
     */
    private ?LicenceVerdict $remembered = null;

    public function read(): LicenceVerdict
    {
        if ($this->remembered !== null) {
            return $this->remembered;
        }

        return $this->remembered = $this->look();
    }

    /** ⓘ পরীক্ষায় লাইসেন্স বদলে আবার পড়তে হয়। */
    public function forget(): void
    {
        $this->remembered = null;
    }

    private function look(): LicenceVerdict
    {
        $path = storage_path('app/'.self::PATH);

        if (! File::exists($path)) {
            return LicenceVerdict::missing();
        }

        /*
         * ⚠️ পুরো পড়াটা `try`-এর ভিতরে, আর প্রতিটা ব্যর্থতার উত্তর এক:
         * **অবৈধ**।
         *
         * ⛔ আলাদা আলাদা বার্তা দিলে ("সই মেলেনি" বনাম "JSON ভাঙা")
         * সেটা একটা মানচিত্র হত — কেউ চেষ্টা করতে করতে শিখে যেতেন
         * কোন ঘরটা কীভাবে গড়তে হয়। ⓘ পর্দায় মানুষ যা দেখেন সেটা এক
         * লাইন: *"কাগজটা পড়া গেল না"*।
         */
        try {
            $raw = (string) File::get($path);
            $envelope = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($envelope) || ! isset($envelope['claims'], $envelope['signature'])) {
                return LicenceVerdict::unreadable();
            }

            /*
             * ⭐ সই করা হয় **হুবহু যে লেখাটা** কাগজে আছে তার উপর।
             *
             * ⛔ আবার `json_encode()` করে মেলালে ভুল হত: ঘরের ক্রম বা
             * একটা ফাঁকা অক্ষর বদলালেই সই মিলত না, অথচ কাগজটা আসল।
             * ⓘ তাই দাবিগুলো কাগজে **একটা স্ট্রিং হিসেবেই** বসে।
             */
            $claimsJson = (string) $envelope['claims'];
            $signature = base64_decode((string) $envelope['signature'], true);
            $publicKey = base64_decode((string) config('abos.licence.public_key', ''), true);

            if ($signature === false || $publicKey === false || $publicKey === '') {
                return LicenceVerdict::unreadable();
            }

            if (! sodium_crypto_sign_verify_detached($signature, $claimsJson, $publicKey)) {
                return LicenceVerdict::forged();
            }

            $claims = json_decode($claimsJson, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($claims)) {
                return LicenceVerdict::unreadable();
            }

            return LicenceVerdict::valid(Licence::fromVerifiedClaims($claims));
        } catch (Throwable) {
            return LicenceVerdict::unreadable();
        }
    }
}
