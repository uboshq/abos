<?php

declare(strict_types=1);

namespace App\Core\Engines\Backup;

/**
 * সার্ভারের কোন কোন ড্রাইভে লেখা যায় — যাতে গ্রাহক তালিকা থেকে বাছেন,
 * পথ টাইপ না করে।
 *
 * ── কেন এটা লাগে ─────────────────────────────────────────────────────
 * মালিকের কথা (৩ সেপ্টেম্বর): *"user zate nijei pendrive, others drive
 * egulo select korte pare"*। আজ গন্তব্য বসে `.env`-এ
 * (`ABOS_BACKUP_MIRROR`) — অর্থাৎ **কেবল ডেভেলপার**। বিক্রির পণ্যে
 * সেটা অসমাপ্ত।
 *
 * ── ⚠️ কার মেশিনের ড্রাইভ — আর এখানেই সবচেয়ে বড় ভুল বোঝাবুঝি ─────────
 *
 * ```
 * PHP যা দেখে    →  যে মেশিনে ABOS চলছে, তার ড্রাইভ
 * PHP যা দেখে না  →  যে মেশিন থেকে ব্রাউজার খোলা, তার ড্রাইভ
 * ```
 *
 * অফিসের মেশিনে ABOS চললে পেনড্রাইভটা ওখানেই লাগানো থাকে, আর এই
 * তালিকা কাজে লাগে। কিন্তু সার্ভার ডেটা সেন্টারে থাকলে **গ্রাহকের
 * নিজের ল্যাপটপের পেনড্রাইভ এখানে কোনোদিন দেখা যাবে না** — তখন পথটা
 * "ব্যাকআপ নামান" বোতাম, এই তালিকা নয়।
 *
 * তাই এই ক্লাসটা একটা **সুবিধা**, একমাত্র পথ নয়। খালি তালিকা ফেরত
 * দেওয়া বৈধ উত্তর, ব্যর্থতা নয়।
 */
final class DriveScanner
{
    /**
     * ⚠️ যেসব জায়গায় ব্যাকআপ রাখা যাবে না।
     *
     * গ্রাহক `C:\Windows` বা অ্যাপের নিজের ফোল্ডার বেছে ফেলতে পারেন —
     * প্রথমটা সিস্টেম ভাঙে, দ্বিতীয়টা আরও সূক্ষ্মভাবে খারাপ:
     * **ব্যাকআপ নিজের ভেতরে নিজেকে রাখতে পারে না**, আর পরের ব্যাকআপ
     * আগেরটাকে ভেতরে নিয়ে ক্রমশ ফুলতে থাকত।
     */
    private const FORBIDDEN = ['windows', 'program files', 'program files (x86)', 'system32'];

    /**
     * লেখা যায় এমন ড্রাইভ ও তাদের খালি জায়গা।
     *
     * @return list<array{path: string, free: ?int, total: ?int, removable: bool}>
     */
    public function drives(): array
    {
        return PHP_OS_FAMILY === 'Windows'
            ? $this->windowsDrives()
            : $this->unixMounts();
    }

    /** @return list<array{path: string, free: ?int, total: ?int, removable: bool}> */
    private function windowsDrives(): array
    {
        $rows = [];

        foreach (range('C', 'Z') as $letter) {
            $path = $letter.':'.DIRECTORY_SEPARATOR;

            if (! @is_dir($path) || ! @is_writable($path)) {
                continue;
            }

            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            $rows[] = [
                'path' => $path,
                'free' => $free === false ? null : (int) $free,
                'total' => $total === false ? null : (int) $total,
                'removable' => $this->looksRemovable($total === false ? null : (int) $total),
            ];
        }

        return $rows;
    }

    /**
     * ⓘ macOS-এ লাগানো ড্রাইভ `/Volumes`-এ, Linux-এ `/media` বা `/mnt`।
     *
     * সার্ভারের গোটা ফাইলসিস্টেম ঘেঁটে দেখা হয় না — একটা ভুল পথ
     * বেছে ফেলার সুযোগ কমানোই এখানে নিরাপত্তা।
     */
    private function unixMounts(): array
    {
        $rows = [];

        foreach (['/Volumes', '/media', '/mnt'] as $base) {
            if (! @is_dir($base)) {
                continue;
            }

            foreach ((array) @scandir($base) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $path = $base.'/'.$name;

                if (! @is_dir($path) || ! @is_writable($path)) {
                    continue;
                }

                $free = @disk_free_space($path);
                $total = @disk_total_space($path);

                $rows[] = [
                    'path' => $path,
                    'free' => $free === false ? null : (int) $free,
                    'total' => $total === false ? null : (int) $total,
                    'removable' => true,
                ];
            }
        }

        return $rows;
    }

    /**
     * এই পথটা কি ব্যাকআপ রাখার জন্য গ্রহণযোগ্য?
     *
     * ⚠️ পর্দার তালিকা থেকে বাছাই যথেষ্ট নয় — গ্রাহক হাতেও পথ লিখতে
     * পারেন, আর ফর্ম বাইপাস করেও পাঠানো যায়। তাই যাচাইটা সংরক্ষণের
     * মুহূর্তে, তালিকা বানানোর সময় নয়।
     */
    public function isAcceptable(string $path): bool
    {
        $clean = strtolower(str_replace('\\', '/', trim($path)));

        if ($clean === '') {
            return false;
        }

        foreach (self::FORBIDDEN as $bad) {
            if (str_contains($clean, '/'.$bad)) {
                return false;
            }
        }

        /*
         * অ্যাপের নিজের ফোল্ডারের ভেতরে নয়।
         *
         * ⓘ `config/abos.php`-তে এই সিদ্ধান্তটা আগেই নেওয়া আছে, আর
         * কারণটা সেখানেই লেখা: `storage/` নিজেও ব্যাকআপের অংশ, তাই
         * ব্যাকআপ ওখানে রাখলে সে নিজেকে ভেতরে নিয়ে বসত।
         */
        /*
         * ⛔ পথটা সত্যি কোথায় — তার ওপর বিচার, লেখা অক্ষরের ওপর নয় (১ অক্টোবর ২০২৬, bb-র নিরীক্ষা)।
         *
         * ⓘ আগে কেবল "অ্যাপের পথ দিয়ে শুরু কি না" দেখা হত। ফলে `public` (আপেক্ষিক — কনসোলে অ্যাপের
         * গোড়া থেকে খোলে), `/tmp/../<অ্যাপ>/public` বা অ্যাপে ফেরা symlink পার পেত, আর রাতের ডাম্প
         * ওয়েব থেকে নামানো যায় এমন ফোল্ডারে বসত। ⓘ উল্টো দিকে `…/abos-backups`-এর মতো পাশের
         * ফোল্ডার কেবল নামের শুরু মেলায় আটকাত।
         */
        if (! preg_match('#^([a-z]:)?/#', $clean)) {
            return false; // আপেক্ষিক পথ নয়
        }

        // ⓘ আসল অক্ষরে খোলা (লিনাক্সে বড়-ছোট হাতের ফারাক আছে), তুলনা ছোট হাতে
        $real = $this->resolve(str_replace('\\', '/', trim($path)));

        foreach ([base_path(), public_path()] as $root) {
            $root = $this->resolve(str_replace('\\', '/', $root));

            if ($real === $root || str_starts_with($real, $root.'/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * `.`/`..` মিলিয়ে, আর ডিস্কে যতটা আছে ততটা `realpath()` দিয়ে (symlink খুলে) — ছোট হাতের, `/` দিয়ে।
     */
    private function resolve(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $i => $part) {
            if ($part === '..') {
                if (count($parts) > 1) {
                    array_pop($parts);
                }
            } elseif ($part !== '.' && ($part !== '' || $i === 0)) {
                $parts[] = $part;
            }
        }

        $probe = implode('/', $parts) === '' ? '/' : implode('/', $parts);
        $tail = [];

        // ⓘ যে অংশটা এখনো নেই (নতুন ফোল্ডার), তার আগের অংশ পর্যন্ত আসল পথে খোলা
        while (! file_exists($probe) && dirname($probe) !== $probe) {
            array_unshift($tail, basename($probe));
            $probe = dirname($probe);
        }

        $base = rtrim(str_replace('\\', '/', realpath($probe) ?: $probe), '/');

        return strtolower($tail === [] ? $base : $base.'/'.implode('/', $tail));
    }

    /**
     * আকার দেখে অনুমান — পেনড্রাইভ না ভেতরের ডিস্ক।
     *
     * ⚠️ **এটা অনুমান, তথ্য নয়** — PHP-তে ড্রাইভের ধরন জানার কোনো
     * নির্ভরযোগ্য উপায় নেই। তাই এটা কেবল পর্দায় একটা ইঙ্গিত ("সম্ভবত
     * অপসারণযোগ্য"), আর কোনো সিদ্ধান্ত এর উপর দাঁড়ায় না। ভুল হলে
     * গ্রাহক নিজের চোখেই দেখতে পান কোনটা তাঁর পেনড্রাইভ।
     */
    private function looksRemovable(?int $total): bool
    {
        return $total !== null && $total < 128 * 1024 ** 3;
    }
}
