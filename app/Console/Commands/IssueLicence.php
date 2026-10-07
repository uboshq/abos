<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Licence\LicenceReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * একটা লাইসেন্স কাগজ বানানো — মালিকের নিজের মেশিনে, একবার।
 *
 * ── ⛔ গোপন চাবি কোথাও সংরক্ষিত হয় না ──────────────────────────────
 * চাবিটা **যুক্তি হিসেবে নেওয়া হয় না** (`--key=…`), কারণ তাহলে সেটা
 * shell-এর ইতিহাসে, `ps`-এর তালিকায় আর লগে বসে থাকত। ⚠️ নেওয়া হয়
 * লুকানো প্রশ্ন করে, আর ব্যবহারের পর স্মৃতি থেকে মুছে ফেলা হয়।
 *
 * ⓘ ফাইলেও রাখা হয় না, `.env`-এও নয়। ⭐ মালিক প্রতিবার নিজের সিন্দুক
 * থেকে এনে বসান — এটা অসুবিধা, আর অসুবিধাটাই এখানে নিরাপত্তা।
 *
 * ── ⚠️ কমান্ডটা লাইভ সার্ভারে চালানোর জিনিস নয় ─────────────────────
 * ⛔ চালালে চাবিটা ঐ মেশিনে টাইপ করা হত, আর ক্রেতার সার্ভারে সেটা
 * কোনোদিন যাওয়া উচিত নয়। ⓘ বানানো হয় মালিকের নিজের মেশিনে, আর
 * কেবল **ফলাফল ফাইলটা** ক্রেতাকে দেওয়া হয়।
 */
final class IssueLicence extends Command
{
    protected $signature = 'abos:issue-licence
        {--buyer= : ক্রেতার নাম, যেমন কাগজে লেখা থাকবে}
        {--issued-to= : কোন সার্ভার বা ডোমেইনের জন্য}
        {--companies=0 : সর্বোচ্চ কয়টা কোম্পানি (০ = সীমা নেই)}
        {--expires= : শেষ দিন YYYY-MM-DD (খালি = চিরকালীন)}
        {--out=licence.json : কোথায় লেখা হবে}';

    protected $description = 'একটা সই করা লাইসেন্স কাগজ তৈরি করে (গোপন চাবি লাগে)';

    public function handle(): int
    {
        $buyer = (string) ($this->option('buyer') ?: $this->ask('ক্রেতার নাম'));
        $issuedTo = (string) ($this->option('issued-to') ?: $this->ask('কোন সার্ভার/ডোমেইনের জন্য'));
        $expires = (string) ($this->option('expires') ?? '');

        /*
         * ⭐ চিরকালীন কাগজ একটা সিদ্ধান্ত, দুর্ঘটনা নয়।
         *
         * ⚠️ `--expires` না দিলে চুপচাপ চিরকালীন বানিয়ে দিলে একদিন
         * কেউ ভুল করে একজন ক্রেতাকে চিরকালীন লাইসেন্স দিয়ে ফেলতেন,
         * আর সেটা **ফেরানো যেত না**।
         */
        if ($expires === '' && ! $this->confirm('মেয়াদের তারিখ দেওয়া হয়নি — এই কাগজটা চিরকালীন হবে। ঠিক আছে?', false)) {
            $this->warn('বাতিল করা হলো। `--expires=YYYY-MM-DD` দিন।');

            return self::FAILURE;
        }

        /*
         * ⛔ চাবিটা লুকানো প্রশ্নে — যুক্তিতে নয়।
         *
         * ⓘ `secret()` পর্দায় কিছু দেখায় না, আর মানটা shell-এর
         * ইতিহাসে বা `ps`-এ যায় না।
         */
        $secret = (string) $this->secret('গোপন চাবি (base64) — পর্দায় দেখাবে না');

        if (trim($secret) === '') {
            $this->error('চাবি ছাড়া কাগজ বানানো যায় না।');

            return self::FAILURE;
        }

        $claims = json_encode([
            'buyer' => $buyer,
            'issued_to' => $issuedTo,
            'companies' => (int) $this->option('companies'),
            'issued_on' => now()->toDateString(),
        ] + ($expires !== '' ? ['expires_on' => $expires] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($claims === false) {
            $this->error('দাবিগুলো লেখা গেল না।');

            return self::FAILURE;
        }

        try {
            $key = base64_decode(trim($secret), true);

            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                $this->error('চাবিটা ed25519-এর গোপন চাবি বলে মনে হচ্ছে না।');

                return self::FAILURE;
            }

            $signature = sodium_crypto_sign_detached($claims, $key);
        } catch (Throwable $e) {
            $this->error('সই করা গেল না: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            /*
             * ⭐ ব্যবহারের পর স্মৃতি থেকে মুছে ফেলা।
             *
             * ⓘ `sodium_memzero` ঘরটা শূন্য করে দেয়, তাই core dump বা
             * সোয়াপে চাবিটা পড়ে থাকে না। ⚠️ `finally`-তে, কারণ
             * ব্যতিক্রম উঠলেও মুছতে হবে।
             */
            if (isset($key) && is_string($key)) {
                sodium_memzero($key);
            }

            sodium_memzero($secret);
        }

        $path = base_path((string) $this->option('out'));

        file_put_contents($path, json_encode([
            'claims' => $claims,
            'signature' => base64_encode($signature),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info('কাগজটা লেখা হলো: '.$path);
        $this->line('');
        $this->line('⚠️ ক্রেতার সার্ভারে রাখুন: storage/app/'.LicenceReader::PATH);

        return self::SUCCESS;
    }
}
