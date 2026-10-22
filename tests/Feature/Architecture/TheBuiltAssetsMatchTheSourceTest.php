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

        /*
         * ⭐ সময় গিটের শেষ কমিট থেকে, ফাইলের mtime থেকে নয় — ১২ অক্টোবর ২০২৬ (fe; a6, ec, a5 তিনজনই নতুন clone-এ মিথ্যা লাল)।
         * ⓘ নতুন clone-এ প্রতিটা ফাইলের mtime হয় checkout-এর মুহূর্ত, আর লেখার ক্রমে JS-গুলো ম্যানিফেস্টের পরে পড়লেই "বিল্ড পুরনো"।
         * ⭐ কমিটের সময় সেই প্রশ্নেরই উত্তর: সোর্স বদলে বিল্ড না বানিয়ে কমিট করলে সোর্সের কমিট ম্যানিফেস্টের পরের — লাল।
         * ⚠️ অকমিট বদল (বা গিট নেই) — mtime-এ ফেরে, তাই কাজের গাছে "বদলালাম, বানাইনি" আগের মতোই ধরা পড়ে ([[changedAt()]])।
         */
        $builtAt = $this->changedAt($manifest);
        $newer = [];
        $looked = 0;

        foreach (self::SOURCES as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->filesIn($path) as $file) {
                $looked++;

                if ($this->changedAt($file) > $builtAt) {
                    $newer[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        /*
         * ⛔ এই গোনাটা না থাকায় পাহারাটা অন্ধ ছিল — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মেপে দেখা: `SOURCES`-এ না-থাকা একটা ফোল্ডার বসালে
         * `is_dir` ব্যর্থ হয়, `continue` চলে, `$newer` খালি থাকে —
         * আর দাবিটা **শূন্যটা ফাইল দেখে পাস করে**।
         *
         * ⚠️ ফোল্ডার সরানো কাল্পনিক নয় — `resources/js` থেকে একটা
         * অংশ অন্য নামে সরলে এই তালিকা হালনাগাদ করতে ভুললেই
         * পাহারাটা চিরকাল সবুজ হয়ে যেত, আর পুরনো বিল্ড লাইভে যেত।
         *
         * ⓘ মেঝেটা ২০, আসল সংখ্যা ৩২ — স্বাভাবিক রিফ্যাক্টরে ফাইল
         * কমতে-বাড়তে পারে বলে হুবহু বসানো হয়নি; কিন্তু শূন্য বা
         * হাতেগোনা কয়টা দেখা মানে শনাক্তকারীটাই ভাঙা।
         */
        $this->assertGreaterThan(20, $looked, implode("\n", [
            '⛔ পাহারাটা মাত্র '.$looked.'টা সোর্স ফাইল দেখেছে।',
            '',
            'ⓘ `SOURCES`-এর ফোল্ডারগুলো এখনো আছে কি না দেখুন: '
                .implode(', ', self::SOURCES),
            '',
            '⚠️ এটা না ধরলে নিচের দাবিটা কিছু না দেখেই সবুজ থাকত।',
        ]));

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
     * ফাইলটা শেষ কবে বদলেছে — কমিট করা আর অবদলানো হলে গিটের শেষ কমিটের সময়, নইলে mtime।
     *
     * ⓘ "অবদলানো" মাপা হয় `git status --porcelain`-এ: কাজের গাছে বদলানো বা নতুন ফাইলের কমিট-সময় পুরনো, অথচ বদলটা নতুন —
     * তাই ওখানে mtime-ই সত্য। গিট না থাকলে (রপ্তানি করা গাছ) সবকিছু আগের মতো mtime-এ।
     */
    private function changedAt(string $file): int
    {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen(base_path()) + 1));
        $git = 'git -C '.escapeshellarg(base_path());

        if (trim((string) @shell_exec($git.' status --porcelain -- '.escapeshellarg($relative).' 2>&1')) === '') {
            $committed = trim((string) @shell_exec($git.' log -1 --format=%ct -- '.escapeshellarg($relative).' 2>/dev/null'));

            if (ctype_digit($committed)) {
                return (int) $committed;
            }
        }

        return (int) filemtime($file);
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
            /*
             * ⓘ পরীক্ষার ফাইল (`*.test.js`, `*.spec.js`) বিল্ডে যায় না — বদলালেও বান্ডেল একই থাকে, তাই নতুন বিল্ডে কমিট করার মতো কিছুই
             * থাকে না, অথচ পাহারা লাল হত (লাইভ-হটফিক্স, ২১ অক্টোবর ২০২৬: shell.test.js বদলাতেই, shell.js না বদলানো সত্ত্বেও)। পাহারা
             * কেবল সেই ফাইলগুলো দেখে যা বান্ডেলে ঢোকে — বাকি সব আগের মতোই।
             */
            if ($file->isFile() && ! str_contains($file->getPathname(), 'node_modules')
                && preg_match('/\.(test|spec)\.[cm]?js$/', $file->getFilename()) !== 1) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
