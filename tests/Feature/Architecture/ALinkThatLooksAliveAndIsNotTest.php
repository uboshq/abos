<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * যে লিংক দেখতে জীবন্ত, অথচ চাপলে ৪০৪।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * রিপোর্টের ঠিকানাগুলো `route('accounts.report.show', ['slug' => 'x'])`
 * আকারে লেখা হয়। রুটের নামটা আসল, তাই `route()` দিব্যি একটা ঠিকানা
 * বানিয়ে দেয় — **কিন্তু `slug`-টা ভুল হলে কন্ট্রোলার `abort(404)` করে**।
 *
 * ৪ সেপ্টেম্বর ২০২৬-এ ঠিক এটাই দুইবার ঘটেছে:
 *   • ড্যাশবোর্ডের বকেয়ার তালিকা `'receivable'`-এ পাঠাত — ওই নামে
 *     কোনো রিপোর্টই নেই (দশটার একটাও নয়)
 *   • সরবরাহকারীর দিকেও একই ভুল হচ্ছিল
 *
 * **দুইবার মানে ধরন, দুর্ঘটনা নয়** — তাই পাহারাটা।
 *
 * ── ⚠️ কেন `Route::has()` দিয়ে এটা ধরা যায় না ────────────────────────
 * `Route::has('accounts.report.show')` বলে **নামটা আছে কি না** — সে
 * `slug` দেখেই না। তাই ভুল slug-এ:
 *
 *     লিংকটা তৈরি হয় ✅ · পর্দায় দেখা যায় ✅ · চাপলে ৪০৪ ❌
 *     আর **কোনো টেস্ট লাল হয় না**
 *
 * এটাই সবচেয়ে নীরব ধরনের ভাঙা দরজা: কোড ঠিক দেখায়, পাহারা সবুজ থাকে,
 * আর ভুলটা জানা যায় কেবল যেদিন কেউ সারিটায় ক্লিক করেন।
 *
 * ── কেন তালিকাটা এখানে হাতে লেখা হয় না ───────────────────────────────
 * ⚠️ বৈধ slug-গুলো কন্ট্রোলারের নিজের `SLUGS` ধ্রুবক থেকে **reflection
 * দিয়ে পড়া হয়**। এখানে নকল একটা তালিকা রাখলে ছয় মাস পরে দুইটা আলাদা
 * হয়ে যেত, আর **গার্ডটা নিজেই মিথ্যা বলত** — নতুন রিপোর্ট যোগ করলে
 * লাল হত, আর মুছে ফেলা রিপোর্টে সবুজ।
 *
 * কোন রুট কোন কন্ট্রোলারের, সেটাও অনুমান নয় — **রুটের নিবন্ধন থেকেই**
 * নেওয়া।
 */
final class ALinkThatLooksAliveAndIsNotTest extends TestCase
{
    public function test_every_report_link_names_a_slug_that_exists(): void
    {
        $valid = $this->slugsByRouteName();

        $this->assertNotSame([], $valid, 'একটাও report.show রুট পাওয়া গেল না — পাহারাটা কি অন্ধ?');

        $dead = [];

        foreach ($this->sourceFiles() as $file => $source) {
            foreach ($this->linksIn($source) as [$routeName, $slug]) {
                if (! isset($valid[$routeName])) {
                    $dead[] = "{$file} — '{$routeName}' নামে কোনো রুট নেই";

                    continue;
                }

                if (! in_array($slug, $valid[$routeName], true)) {
                    $dead[] = "{$file} — {$routeName} '{$slug}' চেনে না "
                        .'(চেনে: '.implode(', ', $valid[$routeName]).')';
                }
            }
        }

        $this->assertSame([], $dead, implode("\n", array_merge(
            ['এই লিংকগুলো দেখতে জীবন্ত, চাপলে ৪০৪:', ''],
            $dead,
            ['', 'কন্ট্রোলারের SLUGS-এ নেই এমন slug লিখলে route() তবু ঠিকানা বানায়।'],
        )));
    }

    /**
     * প্রতিটা `*.report.show` রুটের বৈধ slug-গুলো — কন্ট্রোলার থেকেই।
     *
     * @return array<string, list<string>>
     */
    private function slugsByRouteName(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_ends_with($name, '.report.show')) {
                continue;
            }

            $action = $route->getAction('controller');

            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class] = explode('@', $action);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->hasConstant('SLUGS')) {
                continue;
            }

            /*
             * ধ্রুবকটা `private`, তাই reflection দিয়ে পড়া।
             *
             * ⓘ PHP ৮.১ থেকে `getValue()` নিজেই দৃশ্যমানতা উপেক্ষা করে,
             * আর `setAccessible()` ৮.৫-এ **উঠেই গেছে** — ওটা ডাকলে
             * পরীক্ষাটা ত্রুটিতে ভেঙে পড়ে (মেপে দেখা)।
             *
             * ⓘ private রাখাটা ঠিক:
             * বাইরের কেউ ওটা পড়ে সিদ্ধান্ত নিলে দ্বিতীয় একটা সত্য
             * তৈরি হত। পরীক্ষাটা ব্যতিক্রম, কারণ সে সত্যটা **যাচাই**
             * করে, ব্যবহার করে না।
             */
            $constant = $reflection->getReflectionConstant('SLUGS');

            $out[$name] = array_map('strval', array_keys((array) $constant->getValue()));
        }

        return $out;
    }

    /**
     * এক ফাইলে যত রিপোর্ট-লিংক।
     *
     * ⓘ কেবল **হাতে লেখা** slug দেখা হয়। `['slug' => $row->slug]`
     * ধরনের চলক এখানে যাচাই করা যায় না — কী মান আসবে তা চলার সময়
     * ঠিক হয়। ওগুলো ইচ্ছাকৃতভাবে বাদ, কারণ **অর্ধেক যাচাই করে
     * "সব ঠিক" বলার চেয়ে না বলা ভালো**।
     *
     * @return list<array{0: string, 1: string}>
     */
    private function linksIn(string $source): array
    {
        $pattern = "/route\(\s*'([a-z_]+\.report\.show)'\s*,\s*\[\s*'slug'\s*=>\s*'([^']+)'/";

        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => [$m[1], $m[2]], $matches);
    }

    /** @return array<string, string> */
    private function sourceFiles(): array
    {
        $out = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $out[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())]
                    = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }
}
