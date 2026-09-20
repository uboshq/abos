<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * বিল্ড করা ফাইলগুলো সোর্সের চেয়ে পুরনো নয়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ⛔ ব্যাংক ঋণের ফর্মে ধরনভেদে ঘর দেখানোর কাজটা `resources/js`-এ হয়েছিল।
 * PHP ডিপ্লয় হলো, **JavaScript হলো না** — আর মালিক হার্ড রিফ্রেশ দিয়েও
 * আগের ফর্মটাই দেখলেন।
 *
 * ── ⓘ কেন এটা নিঃশব্দে ঘটে ──────────────────────────────────────────
 * লাইভ সার্ভারে node নেই, তাই `public/build` **কমিট করা হয়** — সার্ভারে
 * বানানো হয় না। মানে `resources/js` বদলানো আর সেটা লাইভে পৌঁছানো দুইটা
 * আলাদা কাজ, আর দ্বিতীয়টা ভুলে গেলে কোথাও কিছু ভাঙে না: পাতা ২০০ দেয়,
 * পরীক্ষা সবুজ থাকে, ⛔ কেবল আচরণটা পুরনো।
 *
 * ⭐ তাই মাপটা সময়ের: সোর্সের কোনো ফাইল বিল্ডের চেয়ে নতুন হলে লাল।
 * ⚠️ এটা বিল্ডের **বিষয়বস্তু** মেলায় না, কেবল "কেউ বানাতে ভুলেছে কি না"
 * বলে — আর ভুলটা ঠিক ঐ আকারেই হয়।
 */
final class TheBuiltAssetsMatchTheSourceTest extends TestCase
{
    /** যে ফোল্ডারগুলো বিল্ডে যায়। */
    private const SOURCES = ['resources/js', 'resources/css'];

    public function test_nobody_changed_the_source_without_rebuilding(): void
    {
        $manifest = base_path('public/build/manifest.json');

        /*
         * ⚠️ প্রথমে এটা — ম্যানিফেস্ট না থাকলে নিচের তুলনাটা কিছুই মাপত
         * না, আর পরীক্ষাটা নীরবে পাশ করত।
         */
        $this->assertFileExists($manifest, implode("\n", [
            '⛔ `public/build/manifest.json` নেই।',
            '',
            'ⓘ লাইভে node নেই, তাই বিল্ড কমিট করা হয়। চালান: npm run build',
        ]));

        $builtAt = (int) filemtime($manifest);
        $newer = [];

        foreach (self::SOURCES as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->filesIn($path) as $file) {
                if ((int) filemtime($file) > $builtAt) {
                    $newer[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        sort($newer);

        $this->assertSame([], $newer, implode("\n", array_merge(
            ['⛔ এই ফাইলগুলো বিল্ডের পরে বদলেছে, অর্থাৎ বিল্ডটা পুরনো:', ''],
            array_slice($newer, 0, 20),
            ['', 'চালান: npm run build — তারপর `public/build` কমিট করুন।',
                '',
                '⚠️ না করলে লাইভে পুরনো আচরণই চলতে থাকবে, আর কোথাও কিছু',
                'ভাঙবে না — পাতা ২০০ দেবে, পরীক্ষা সবুজ থাকবে।']
        )));
    }

    /**
     * ⓘ `node_modules` বা বিল্ডের নিজের ফল বাদ — ওগুলো সোর্স নয়।
     *
     * @return list<string>
     */
    private function filesIn(string $dir): array
    {
        $out = [];

        $walker = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walker as $file) {
            if ($file->isFile() && ! str_contains($file->getPathname(), 'node_modules')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
