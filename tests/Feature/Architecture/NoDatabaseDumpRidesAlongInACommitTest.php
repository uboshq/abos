<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ডাটাবেসের ডাম্প বা গোপন চাবি যেন কমিটে চড়ে না বসে।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * নিরীক্ষায় পাওয়া গেল গিট ইতিহাসে **দুইটা ডাটাবেস ডাম্প** বসে আছে
 * (`eaab669b`, `31498303`, ৪ অগাস্ট ২০২৬)। পরে মুছে `/backups`
 * উপেক্ষার তালিকায় গেছে, কিন্তু ব্লব দুইটা রয়ে গেছে — যে কেউ ক্লোন
 * করলেই পায়।
 *
 * ⓘ ভিতরে কেবল `@abos.test` নমুনা অ্যাকাউন্ট, তাই আজকের ঝুঁকি কম —
 * **যদি না** কোনো আসল পাসওয়ার্ড নমুনার সাথে মিলে যায়। ইতিহাস নতুন
 * করে লেখা সবার ক্লোন ভাঙে, তাই সেটা মালিকের সিদ্ধান্ত।
 *
 * ⭐ কিন্তু **আবার ঘটা** ঠেকানো সিদ্ধান্তের অপেক্ষা রাখে না, আর সেটাই
 * এই পাহারা। ⚠️ ওটা ঘটেছিল কারণ কেউ `git add .` চালিয়েছিলেন, আর
 * তখন `/backups` উপেক্ষায় ছিল না।
 */
final class NoDatabaseDumpRidesAlongInACommitTest extends TestCase
{
    /** যে চেহারার ফাইল কখনো কমিটে যাওয়ার কথা নয়। */
    private const NEVER = [
        '.sql', '.sql.gz', '.dump', '.bak',
        '.pem', '.key', '.p12', '.pfx', '.jks',
    ];

    /**
     * ⓘ যেগুলো দেখতে ঝুঁকির মতো, কিন্তু নয় — কারণসহ।
     *
     * @var array<string, string>
     */
    private const FINE = [
        'database/schema/mysql-schema.sql' => 'কেবল কাঠামো, কোনো সারি নেই — ল্যারাভেলের নিজের ছাঁচ',
    ];

    public function test_no_dump_or_private_key_is_tracked(): void
    {
        $tracked = $this->trackedFiles();

        /*
         * ⚠️ আগে এটা: গিটের তালিকা না পেলে নিচের ছাঁকনি খালি তালিকায়
         * চলত আর পাহারাটা চিরকাল সবুজ থাকত।
         */
        $this->assertGreaterThan(
            1000,
            count($tracked),
            'গিট থেকে মাত্র '.count($tracked).'টা ফাইল পাওয়া গেল — তালিকাটাই ভেঙেছে।'
        );

        $found = [];

        foreach ($tracked as $path) {
            if (isset(self::FINE[$path])) {
                continue;
            }

            foreach (self::NEVER as $ext) {
                if (str_ends_with(strtolower($path), $ext)) {
                    $found[] = $path;
                    break;
                }
            }
        }

        sort($found);

        $this->assertSame([], $found, implode("\n", array_merge(
            ['⛔ এই ফাইলগুলো কমিটে আছে, আর ওদের থাকার কথা নয়:', ''],
            $found,
            ['',
                '⚠️ একবার কমিট হলে গিট ইতিহাসে থেকে যায় — পরে মুছলেও',
                'ক্লোন করলেই পাওয়া যায়। ডাটাবেস ডাম্পে ব্যবহারকারীর সারি',
                'ও পাসওয়ার্ডের হ্যাশ থাকে।',
                '',
                'ⓘ সত্যিই লাগলে উপরের FINE তালিকায় কারণসহ লিখুন।']
        )));
    }

    /**
     * ⛔ আর উপেক্ষার নিয়মগুলো সত্যিই বসানো আছে তো।
     *
     * ⓘ উপরের দাবিটা বলে "আজ কিছু নেই"; এটা বলে "কাল যোগ করলে ধরা
     * পড়বে"। ⚠️ ২০২৬-এর অগাস্টে ঠিক এই নিয়মটা না থাকাতেই ডাম্প দুইটা
     * ঢুকেছিল।
     */
    public function test_the_places_dumps_live_are_ignored(): void
    {
        foreach (['backups/anything.sql.gz', '.env', 'storage/logs/laravel.log'] as $path) {
            exec(
                'git -C '.escapeshellarg(base_path()).' check-ignore '.escapeshellarg($path).' 2>&1',
                $out,
                $code
            );

            $this->assertSame(0, $code, implode("\n", [
                "⛔ `{$path}` উপেক্ষার তালিকায় নেই।",
                '',
                'ⓘ অর্থাৎ কেউ `git add .` চালালে ওটা কমিটে ঢুকে যাবে।',
            ]));

            $out = [];
        }
    }

    /** @return list<string> */
    private function trackedFiles(): array
    {
        exec('git -C '.escapeshellarg(base_path()).' ls-files 2>&1', $lines, $code);

        return $code === 0 ? array_map('trim', $lines) : [];
    }
}
