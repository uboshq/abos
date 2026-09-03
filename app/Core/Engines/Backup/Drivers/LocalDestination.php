<?php

declare(strict_types=1);

namespace App\Core\Engines\Backup\Drivers;

use App\Core\Engines\Backup\Destination;
use App\Core\Engines\Backup\Health;
use RuntimeException;

/**
 * একটা ফোল্ডার — পেনড্রাইভ, এক্সটার্নাল ড্রাইভ, দ্বিতীয় ডিস্ক, NAS-এর
 * mount করা পথ।
 *
 * ── কেন এটাই প্রথম driver ────────────────────────────────────────────
 * মালিকের নিজের ক্রম (৩ সেপ্টেম্বর): *"Pendrive, external drive,
 * google, dropbox, onedrive egulote rakhar bebostha age korbe"*। আর
 * তিনটা কারণে এটাই আগে হওয়া উচিত:
 *
 * **ক · আজই কাজ করে।** কোনো নিবন্ধন নেই, কোনো OAuth নেই, কারও অনুমতি
 * লাগে না — গ্রাহক শুধু একটা পথ বাছেন।
 *
 * **খ · ⚠️ এটাই একমাত্র যেটা ransomware থামায়।** Google/OneDrive/
 * Dropbox সবসময় সংযুক্ত, তাই ভাইরাস ফাইল নষ্ট করলে **নষ্ট কপিটাও
 * সাথে সাথে sync হয়ে ভালোটাকে চাপা দেয়**। যে ড্রাইভ খুলে রাখা,
 * সেটাকে কেউ ছুঁতে পারে না।
 *
 * **গ · ৮০% ইতিমধ্যেই ছিল** — `ABOS_BACKUP_MIRROR` একটা পথে কপি করত।
 * যা ছিল না: একাধিক গন্তব্য, স্বাস্থ্যের হিসাব, আর কোন ড্রাইভে শেষ
 * কপি কবে গেল তার রেকর্ড।
 *
 * ── ⚠️ যা এই driver-এর দায়িত্ব নয় ────────────────────────────────────
 * ব্যাকআপ নেওয়ার পর ড্রাইভটা **খুলে রাখতে হবে** — সেটা মানুষের কাজ,
 * কোডের নয়। কোড কেবল বলতে পারে "সাত দিন ধরে লাগানো হয়নি", আর
 * [[BackupDestination::daysSinceLastCopy()]] সেটাই বলে।
 */
final class LocalDestination implements Destination
{
    /**
     * @param  array{path: string, label?: string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function put(string $localFile, string $remoteName): void
    {
        $dir = $this->directory();
        $target = $dir.DIRECTORY_SEPARATOR.$remoteName;

        /*
         * ⚠️ আগে অস্থায়ী নামে, তারপর নাম বদলানো।
         *
         * সরাসরি লিখলে কপি করার মাঝপথে ড্রাইভ খুলে গেলে বা বিদ্যুৎ
         * গেলে **একটা অর্ধেক ফাইল** পড়ে থাকত — যেটা দেখতে ব্যাকআপের
         * মতোই, আকারও প্রায় ঠিক, কিন্তু ফেরে না।
         *
         * `rename()` একই ফাইলসিস্টেমে পারমাণবিক, তাই ফাইলটা হয় পুরো
         * থাকে নয় থাকেই না — মাঝামাঝি কিছু নয়।
         */
        $temp = $target.'.part';

        if (! @copy($localFile, $temp)) {
            throw new RuntimeException(__('backup::error.copy_failed', ['path' => $dir]));
        }

        if (! @rename($temp, $target)) {
            @unlink($temp);

            throw new RuntimeException(__('backup::error.rename_failed', ['path' => $dir]));
        }
    }

    public function get(string $remoteName, string $localFile): void
    {
        $source = $this->directory().DIRECTORY_SEPARATOR.$remoteName;

        if (! is_file($source) || ! @copy($source, $localFile)) {
            throw new RuntimeException(__('backup::error.read_failed', ['file' => $remoteName]));
        }
    }

    public function list(): array
    {
        $dir = $this->config['path'] ?? '';

        if ($dir === '' || ! is_dir($dir)) {
            return [];
        }

        $rows = [];

        foreach ((array) @scandir($dir) as $name) {
            $full = $dir.DIRECTORY_SEPARATOR.$name;

            // `.part` বাদ — ওগুলো অসম্পূর্ণ কপি, ব্যাকআপ নয়
            if (! is_file($full) || str_ends_with((string) $name, '.part')) {
                continue;
            }

            $rows[] = [
                'name' => (string) $name,
                'bytes' => (int) filesize($full),
                'at' => date('c', (int) filemtime($full)),
            ];
        }

        return $rows;
    }

    public function delete(string $remoteName): void
    {
        @unlink($this->directory().DIRECTORY_SEPARATOR.$remoteName);
    }

    /**
     * পৌঁছানো যায় কি না — আর **লিখেও দেখা হয়**।
     *
     * ⚠️ `is_dir()` আর `is_writable()` দুইটাই সত্যি বলতে পারে অথচ
     * সত্যিকারের লেখা ব্যর্থ হতে পারে: read-only mount করা ড্রাইভ,
     * ভরা ডিস্ক, নেটওয়ার্ক শেয়ার যেটা সবেমাত্র হারিয়েছে।
     *
     * তাই একটা ছোট ফাইল সত্যিই লিখে, পড়ে, মুছে দেখা হয়। **এক
     * মুহূর্তের কাজ, আর এটাই "পরীক্ষা করুন" বোতামটার পুরো মানে।**
     */
    public function health(): Health
    {
        $dir = (string) ($this->config['path'] ?? '');

        if ($dir === '') {
            return Health::unreachable('backup::health.no_path');
        }

        /*
         * ⚠️ পরীক্ষাটা **অভিভাবক ফোল্ডারের**, পাতার নয় — আর এটাই
         * প্রথম রানে ধরা পড়া বাগটার সারাই।
         *
         * ── কী ভেঙেছিল (৩ সেপ্টেম্বর ২০২৬, প্রথম পরীক্ষায়) ──────────
         * `health()` দেখত `K:\ABOS-BACKUP` আছে কি না, আর ফোল্ডারটা
         * বানাত `put()`। কিন্তু [[BackupRunner]] `put()`-এর **আগে**
         * `health()` ডাকে — তাই একটা নতুন গন্তব্য, যার ফোল্ডারটা
         * এখনো তৈরি হয়নি, **চিরকাল "পাওয়া যাচ্ছে না" দেখাত** আর
         * কোনোদিন একটা কপিও পেত না।
         *
         * নীরব ব্যর্থতা নয় — সারিতে `last_error` লেখা ছিল — কিন্তু
         * কারণটা ভুল বলত: ড্রাইভ লাগানোই আছে, কেবল আমাদের নিজের
         * ফোল্ডারটা এখনো বানানো হয়নি।
         *
         * ── আসল প্রশ্নটা কী ─────────────────────────────────────────
         * "আমাদের ফোল্ডার আছে কি?" নয় — ওটা আমাদেরই বানানোর কথা।
         * প্রশ্নটা: **ড্রাইভটা লাগানো আছে কি, আর সেখানে লেখা যায় কি?**
         * তাই পরীক্ষা হয় সবচেয়ে কাছের যে অভিভাবক সত্যিই আছে তার উপর।
         */
        $probeDir = $dir;

        while (! is_dir($probeDir)) {
            $parent = dirname($probeDir);

            if ($parent === $probeDir) {
                // মূল পর্যন্ত উঠেও কিছু নেই — ড্রাইভটাই নেই
                return Health::unreachable('backup::health.not_found');
            }

            $probeDir = $parent;
        }

        $dir = $probeDir;

        /*
         * ⚠️ শেষের স্ল্যাশ ছেঁটে নেওয়া — নাহলে `K:\` + `\` হয়ে `K:\`।
         *
         * উপরের লুপটা ড্রাইভের মূল পর্যন্ত উঠতে পারে, আর Windows-এ
         * ড্রাইভের মূলের নাম `K:\` — শেষে স্ল্যাშ সহ। ওটার সাথে আবার
         * স্ল্যাশ জুড়লে পথটা অবৈধ হয়, আর `file_put_contents()` চুপচাপ
         * `false` ফেরত দেয়।
         *
         * ফল হত সবচেয়ে বিভ্রান্তিকর ধরনের ভুল: পর্দা বলত **"লেখা
         * যাচ্ছে না — অনুমতি দেখুন"**, অথচ অনুমতিতে কোনো সমস্যাই নেই।
         * মানুষ তখন ড্রাইভের অনুমতি নিয়ে ঘণ্টা কাটাতেন।
         */
        $probe = rtrim($dir, DIRECTORY_SEPARATOR.'/').DIRECTORY_SEPARATOR.'.abos-write-test';

        if (@file_put_contents($probe, 'ok') === false) {
            return Health::unreachable('backup::health.not_writable');
        }

        @unlink($probe);

        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);

        return Health::ok(
            $free === false ? null : (int) $free,
            $total === false ? null : (int) $total,
        );
    }

    public function describe(): string
    {
        return (string) ($this->config['label'] ?? $this->config['path'] ?? '');
    }

    /**
     * ফোল্ডারটা, আর না থাকলে বানিয়ে নেওয়া।
     *
     * ⚠️ বানানোটা কেবল **লেখার সময়**, `health()`-এ নয়। নাহলে একটা ভুল
     * পথ (`K:\Bakup`) পরীক্ষা করতে গিয়ে নিজেই তৈরি হয়ে যেত, আর সবুজ
     * দেখাত — অথচ পেনড্রাইভটা আসলে লাগানোই ছিল না।
     */
    private function directory(): string
    {
        $dir = (string) ($this->config['path'] ?? '');

        if ($dir === '') {
            throw new RuntimeException(__('backup::error.no_path'));
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException(__('backup::error.cannot_create', ['path' => $dir]));
        }

        return $dir;
    }
}
