<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PrintProfile;
use Tests\TestCase;

/**
 * তালিকায় নাম আছে, কাগজে ওটা কেউ চায় না।
 *
 * ── ⛔ যে ভুলটা নীরব ─────────────────────────────────────────────────
 * [[PrintProfile::shows()]] অচেনা নাম পেলে চুপচাপ `false` ফেরত দেয়।
 * ⚠️ তাই `PARTS`-এ একটা নাম বানান-ভুল হলে, কিংবা কাগজ থেকে অংশটা মুছে
 * ফেলার পর নামটা তালিকায় রয়ে গেলে, **কোথাও কিছু লাল হয় না**।
 *
 * ⓘ নিয়ন্ত্রণের পর্দায় সুইচটা ঠিকই আঁকা হয়, মালিক ওটা বন্ধ করেন, সেভ
 * হয়, আর কাগজে কিচ্ছু বদলায় না। ⛔ তিনি তখন ধরে নেন গোটা সুইচের
 * ব্যবস্থাটাই কাজ করে না — একটা মরা নামের জন্য।
 *
 * ── ⚠️ কেন দাবিটা উল্টোমুখী ──────────────────────────────────────────
 * কাগজ যে নাম চায় সেটা তালিকায় আছে কি না — ওটা এমনিতেই ধরা পড়ে, অংশটা
 * তখন ছাপাই হয় না। ⓘ যেদিকটা চুপ থাকে সেটা এই দিক: তালিকার নাম কাগজে
 * কেউ চায় কি না। তাই দাবিটা সেদিকেই।
 */
final class EveryPrintPartIsRealTest extends TestCase
{
    /** কাগজগুলো যেখানে আঁকা হয়। */
    private const PAPERS = 'resources/views/print';

    /**
     * ⭐ তালিকার প্রতিটা নাম কোনো না কোনো কাগজ সত্যিই জিজ্ঞেস করে।
     */
    public function test_every_part_name_is_really_asked_for_on_a_paper(): void
    {
        $missing = $this->namesNoPaperAsksFor(PrintProfile::PARTS);

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['⛔ এই নামগুলো `PrintProfile::PARTS`-এ আছে, কিন্তু কোনো কাগজ চায় না:', ''],
            array_map(fn (string $name) => "  · {$name}", $missing),
            [
                '',
                '⚠️ নিয়ন্ত্রণের পর্দায় এদের সুইচ আঁকা হবে, মালিক বন্ধ করবেন,',
                'আর কাগজে কিছুই বদলাবে না — `shows()` অচেনা নামে নীরবে `false` দেয়।',
                '',
                'ⓘ হয় কাগজে অংশটা সত্যিই বসান, নয় তালিকা থেকে নামটা তুলে নিন।',
            ],
        )));
    }

    /**
     * ⛔ আর পাহারাটা সত্যিই তাকায়।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা কিছুই মাপে না ──────────────────
     * ⓘ [[namesNoPaperAsksFor()]]-এর খোঁজার ধরনটা ভুল হলে — একটা ভুল
     * ফোল্ডার, একটা রেগেক্স যা কিছুতেই মেলে না — সে **সবসময় খালি তালিকা**
     * ফেরত দিত, আর উপরের দাবিটা চিরকাল সবুজ থাকত, কিছু না দেখেই।
     *
     * ⭐ তাই এখানে একটা বানানো নাম ধরিয়ে দেওয়া হয়, যেটা কোনো কাগজে নেই।
     * ⓘ পাহারাটা ওটা ধরতে না পারলে সে আসলে কখনোই কিছু ধরে না।
     */
    public function test_the_guard_would_notice_a_name_no_paper_asks_for(): void
    {
        $invented = 'a_part_no_paper_draws';

        $missing = $this->namesNoPaperAsksFor([...PrintProfile::PARTS, $invented]);

        $this->assertSame([$invented], $missing, implode("\n", [
            '⛔ বানানো একটা নাম ধরিয়ে দেওয়ার পরেও পাহারাটা কিছু বলল না।',
            '',
            '⚠️ অর্থাৎ উপরের দাবিটা কখনোই কিছু মাপেনি — সে খালি জালে',
            'মাছ খুঁজছিল।',
        ]));
    }

    /**
     * এই নামগুলোর মধ্যে কোনগুলো কোনো কাগজ চায় না।
     *
     * ⚠️ খোঁজা হয় `$profile->shows('<নাম>')` ডাকটা ধরে, নামটা লেখা আছে
     * কি না ধরে নয়। ⛔ শুধু নামটা খুঁজলে মন্তব্যে বা ক্লাসের নামে লেখা
     * একটা শব্দও দাবিটাকে সবুজ করে দিত।
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function namesNoPaperAsksFor(array $names): array
    {
        $asked = [];

        foreach ($this->everyPaper() as $file) {
            preg_match_all("/shows\(\s*'([a-z_]+)'/", (string) file_get_contents($file), $found);

            foreach ($found[1] as $name) {
                $asked[$name] = true;
            }
        }

        $this->assertNotSame([], $asked, 'ⓘ একটা কাগজও কোনো অংশ চায়নি — খোঁজার পথটাই ভুল।');

        return array_values(array_filter($names, fn (string $name) => ! isset($asked[$name])));
    }

    /**
     * ছাপার সব ভিউ — ভিতরের ফোল্ডারগুলোসহ।
     *
     * ⓘ `partials/` আলাদা করে ধরতে হয়: ব্যান্ডের উপ-মোট ওখানেই আঁকা,
     * আর ওটাও একটা সুইচের নিচে।
     *
     * @return list<string>
     */
    private function everyPaper(): array
    {
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path(self::PAPERS), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($walk as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotSame([], $files, 'ⓘ ছাপার ফোল্ডারে একটা ভিউও পাওয়া যায়নি।');

        return $files;
    }
}
