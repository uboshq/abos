<?php

declare(strict_types=1);

namespace App\Core\Engines\Backup;

use App\Core\Engines\Backup\Drivers\LocalDestination;
use InvalidArgumentException;

/**
 * একটা গন্তব্যের সারি থেকে তার driver — এক জায়গায়, একবার।
 *
 * ── কেন একটা কারখানা, `match` ছড়ানো নয় ──────────────────────────────
 * `driver` দেখে ক্লাস বাছার কাজটা তিন জায়গায় লাগে: ব্যাকআপ পাঠানোর
 * সময়, স্বাস্থ্য দেখার সময়, আর restore-এর সময়। তিনবার লিখলে একদিন
 * একটায় নতুন driver যোগ করতে ভুলে যাওয়া হত, আর **সেই জায়গাটাই কেবল
 * ব্যর্থ হত** — বাকি দুইটা কাজ করত বলে সমস্যাটা এলোমেলো দেখাত।
 *
 * ── নতুন driver যোগ করার খরচ ─────────────────────────────────────────
 * একটা ক্লাস, আর এখানে একটা লাইন। **টেবিল নয়, মাইগ্রেশন নয়, মডেল নয়**
 * — মালিকের ব্লুপ্রিন্ট ঠিক এটাই চেয়েছিল।
 */
final class DestinationFactory
{
    /**
     * আজ যেগুলো আছে, আর যেগুলো আসছে।
     *
     * ⚠️ ক্রমটা ইচ্ছাকৃত — **গ্রাহক একা সাজাতে পারেন এমনগুলো আগে**।
     * `local` আজ কাজ করে; `sftp` আর `s3`-এ গ্রাহক দুইটা লাইন কপি করে
     * বসাবেন; ক্লাউড তিনটায় ABOS-এর নিজের OAuth নিবন্ধন লাগে, আর অন্য
     * কোম্পানির ব্যবহারের জন্য Google সেটাকে "verified" চায়।
     */
    public const DRIVERS = ['local', 'sftp', 's3'];

    public function make(string $driver, array $config): Destination
    {
        return match ($driver) {
            'local' => new LocalDestination($config),

            /*
             * ⚠️ এখনো লেখা হয়নি — আর ইচ্ছে করেই ব্যতিক্রম, নীরব
             * `null` নয়। একটা গন্তব্য "সাজানো আছে" দেখাচ্ছে অথচ
             * কিছুই পাঠাচ্ছে না — ব্যাকআপে এর চেয়ে বিপজ্জনক অবস্থা
             * নেই, কারণ ভুলটা ধরা পড়ে কেবল যেদিন ফাইলটা দরকার হয়।
             */
            default => throw new InvalidArgumentException(
                __('backup::error.unknown_driver', ['driver' => $driver])
            ),
        };
    }
}
