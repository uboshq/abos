<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * মেনুতে চাওয়া প্রতিটা আইকন সত্যিই আঁকা আছে।
 *
 * ── ⛔ কী ভাঙে, আর কতটা নীরবে ────────────────────────────────────────
 * `<x-ui.icon name="shield" />` অচেনা নাম পেলে **কিছুই আঁকে না** — কোনো
 * ব্যতিক্রম নয়, লগে কিছু নয়, পর্দায় কোনো ফাঁকা বাক্সও নয়। ⓘ কম্পোনেন্ট
 * দুইটা মানচিত্রে খোঁজে (`$glyphs`, `$paths`), না পেলে চুপ করে সরে যায়।
 *
 * ⚠️ ফল: সাইডবারে ঐ একটা সারি আইকনহীন হয়ে বসে থাকে, আর বাকি সব সারির
 * লেখা তার চেয়ে একটু ডানে — চোখে পড়ে, কিন্তু কেউ ওটা বাগ বলে লেখে না।
 *
 * ── ⭐ কেন এই পাহারাটা আজ লেখা হলো, ২৪ সেপ্টেম্বর ২০২৬ ────────────────
 * তিনটা মৃত নাম একসাথে ধরা পড়েছে (`user-switch`, `shield`, `pie-chart`),
 * আর তিনটাই একই দিনে লেখা নতুন মেনু সারিতে। ⓘ অর্থাৎ ভুলটা বিরল নয় —
 * যে নামটা মনে আসে সেটাই লেখা হয়, আর কেউ মিলিয়ে দেখে না।
 *
 * ⛔ আগে এটা ধরার একমাত্র উপায় ছিল পর্দা খুলে চোখে দেখা। ⚠️ আর চোখে
 * দেখার পাহারা মানে **যেদিন কেউ তাকায়নি সেদিন কোনো পাহারা নেই**।
 *
 * ── ⓘ কেন নামগুলো রেজিস্ট্রি থেকে, grep থেকে নয় ──────────────────────
 * ⛔ `module.php` ফাইলগুলো grep করলে মন্তব্যে লেখা নামও মিলত ("আগে
 * এখানে `shield` ছিল"), আর পাহারাটা মিথ্যা অভিযোগ করত। ⚠️ মিথ্যা
 * অভিযোগ করা পাহারা কয়েক দিনেই বন্ধ করে দেওয়া হয়।
 */
final class EveryIconAModuleAsksForIsRealTest extends TestCase
{
    /**
     * ⭐ প্রতিটা ঘোষিত আইকন দুইটা মানচিত্রের অন্তত একটায় আছে।
     */
    public function test_every_icon_a_module_asks_for_is_drawn(): void
    {
        $known = $this->iconsTheComponentDraws();
        $missing = [];

        foreach ($this->iconsTheModulesAskFor() as $name => $where) {
            if (! in_array($name, $known, true)) {
                $missing[] = $name.'  ← '.$where;
            }
        }

        sort($missing);

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['⛔ এই আইকনগুলো মেনুতে চাওয়া হয়েছে, কিন্তু আঁকা নেই:', ''],
            $missing,
            ['',
                '⚠️ `x-ui.icon` অচেনা নাম পেলে কিছুই আঁকে না — কোনো ত্রুটি নেই,',
                'লগে কিছু নেই। সারিটা কেবল আইকনহীন হয়ে বসে থাকে।',
                '',
                'হয় `resources/views/components/ui/icon.blade.php`-এ নামটা যোগ করুন,',
                'নয় মেনুতে এমন একটা নাম দিন যা ইতিমধ্যে আঁকা আছে।']
        )));
    }

    /**
     * ⛔ আর পাহারাটা সত্যিই তাকায় কি না — নিজেই মেপে দেখে।
     *
     * ── ⚠️ কেন এই দাবিটা লাগে ────────────────────────────────────────
     * ⓘ উপরের দাবিটা *"খারাপ কিছু পাওয়া গেল না"* দেখে পাশ করে। ⛔ দুইটা
     * তালিকার যেকোনো একটা খালি এলে সে **চিরকাল সবুজ** থাকত — আর ঠিক
     * সেই রোগটা সারাতেই এই ফাইলটা লেখা।
     *
     * ⚠️ সংখ্যাগুলো আলগা, ইচ্ছাকৃত: প্রশ্নটা *"কয়টা আইকন আছে"* নয়,
     * *"খোঁজাটা আদৌ কিছু পেয়েছে কি না"*। ⛔ শক্ত সংখ্যা ধরলে প্রতিটা
     * নতুন মেনু সারিতে এই পাহারা ভাঙত, আর ভাঙা পাহারা কেউ রাখে না।
     */
    public function test_the_search_itself_found_something(): void
    {
        $asked = $this->iconsTheModulesAskFor();
        $drawn = $this->iconsTheComponentDraws();

        $this->assertGreaterThan(20, count($asked),
            '⛔ মেনুতে মাত্র '.count($asked).'টা আইকন পাওয়া গেল — রেজিস্ট্রি পড়াটাই ভেঙেছে।');

        $this->assertGreaterThan(20, count($drawn),
            '⛔ কম্পোনেন্টে মাত্র '.count($drawn).'টা আইকন পাওয়া গেল — ছাঁচটা ফাইলের সাথে মেলেনি।');

        /*
         * ⓘ আর একটা অস্তিত্বহীন নাম সত্যিই ধরা পড়ে।
         *
         * ⚠️ উপরের দুইটা গুনতি বলে তালিকাগুলো ভরা, কিন্তু **মিলিয়ে
         * দেখাটা কাজ করে কি না** তা বলে না। ⛔ `in_array` কোনোদিন
         * `false` না দিলেও ঐ দুইটা দাবি সবুজ থাকত।
         */
        $this->assertNotContains('a-name-no-icon-will-ever-have', $drawn,
            '⛔ কম্পোনেন্ট যেকোনো নামকেই চেনে বলছে — মিলিয়ে দেখাটা অর্থহীন।');
    }

    /**
     * মডিউলগুলো যে আইকনগুলো চায় — নাম => কোথায়।
     *
     * @return array<string, string>
     */
    private function iconsTheModulesAskFor(): array
    {
        $asked = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $group => $items) {
                foreach ($items as $item) {
                    $icon = $item['icon'] ?? null;

                    if (is_string($icon) && $icon !== '') {
                        $asked[$icon] ??= $module->code.' › '.$group.' › '.($item['route'] ?? '?');
                    }
                }
            }
        }

        return $asked;
    }

    /**
     * কম্পোনেন্ট যে নামগুলো আঁকতে পারে — দুইটা মানচিত্র মিলিয়ে।
     *
     * ── ⓘ দুইটা কেন ─────────────────────────────────────────────────
     * `$glyphs` মডিউলের টাইলের ইমোজি, `$paths` কাজের বোতামের আঁকা।
     * ⚠️ একটাই পড়লে পাহারাটা অর্ধেক নামকে "নেই" বলত — আর মিথ্যা
     * অভিযোগ করা পাহারা কয়েক দিনেই বন্ধ করে দেওয়া হয়।
     *
     * @return list<string>
     */
    private function iconsTheComponentDraws(): array
    {
        $body = File::get(resource_path('views/components/ui/icon.blade.php'));

        /*
         * ⚠️ ছাঁচটা শুরু হয় লাইনের গোড়া থেকে (ফাঁকা জায়গার পর একটা
         * কোট), তাই মন্তব্যের ভিতরে লেখা `'shield'` মেলে না — ওখানে
         * নামটার আগে বাংলা লেখা থাকে।
         */
        preg_match_all("/^\s*'([a-z0-9_-]+)'\s*=>/m", $body, $m);

        return array_values(array_unique($m[1]));
    }
}
