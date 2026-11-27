<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Tests\TestCase;

/**
 * ⭐ হাতে লেখা শিরোনামও রঙিন, আর পাশের লাইন ⓘ-তে — মালিক, ২৩ নভেম্বর ২০২৬ ("শিরোনাম রঙিন, পাশের লেখা ⓘ-তে"), ২৭ নভেম্বর জরিপে ok
 * (মালিকের-ফাইল/শিরোনাম-ⓘ-জরিপ-২৭-নভেম্বর.html)।
 *
 * <p>[[ui/page-header]] আর [[ui/toolbar]]-এর ২৭১ পাতা আগেই রঙ পায় (`data-page-title`, app.css "coloured titles")। জরিপে ৩১টা পাতা
 * নিজের `<h1>` লেখে — তার ২৫টা রঙ পেত না, কারণ রঙের নিয়ম `data-page-title` ধরে। দাবি:
 *  - লগইনের আগের পাতা, ছাপা আর ইমেল ছাড়া কোনো ব্লেডে দেখা-যায় এমন `<h1>` `data-page-title` ছাড়া নেই;
 *  - হোমের "তারিখ · কোম্পানি · শাখা" লাইন `data-page-subtitle` (ABOS রূপে লুকায়) আর একই কথা শিরোনামের ⓘ-তে।
 */
final class EveryHandWrittenTitleWearsTheTitleColourTest extends TestCase
{
    /** ⓘ লগইনের আগের/রূপহীন পাতা (`data-ui` নেই, তাই রঙের নিয়ম খাটেই না), ছাপা, ইমেল, আর পাতা-কাঠামোর অদৃশ্য h1 */
    private const OUTSIDE = ['/portal/', '/auth/', '/errors/', '/print', '/pdf', '/mail', '/emails/', '/scan/', 'SystemAdmin/Resources/views/setup/',
        'notifications/no-access.blade.php', 'Messaging/Resources/views/meeting.blade.php', 'components/ui/page-header.blade.php'];

    public function test_every_visible_hand_written_title_wears_the_title_mark(): void
    {
        exec('git -C '.escapeshellarg(base_path()).' ls-tree -r --name-only HEAD app resources 2>&1', $lines, $code);
        $this->assertSame(0, $code, 'গিটের তালিকা পাওয়া গেল না');

        $looked = 0;
        $bare = [];
        foreach ($lines as $relative) {
            if (! str_ends_with($relative, '.blade.php') || ! is_file(base_path($relative))) {
                continue;
            }
            foreach (self::OUTSIDE as $skip) {
                if (str_contains($relative, $skip)) {
                    continue 2;
                }
            }
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path($relative)));
            foreach ([...preg_match_all('/<h1\b[^>]*>/', $source, $m) ? $m[0] : []] as $tag) {
                $looked++;
                if (str_contains($tag, 'sr-only') || str_contains($tag, 'data-page-title') || str_contains($tag, 'data-view-selector')) {
                    continue;
                }
                $bare[] = $relative.': '.$tag;
            }
        }

        $this->assertGreaterThan(20, $looked, 'মাত্র '.$looked.'টা হাতে লেখা শিরোনাম — তালিকা ভেঙেছে কি না দেখুন।');
        $this->assertSame([], $bare, "⛔ এই শিরোনামগুলো রঙ পায় না (data-page-title নেই):\n  ".implode("\n  ", $bare));
    }

    public function test_the_home_line_folds_into_the_title_info(): void
    {
        foreach (['resources/views/workspace/home.blade.php', 'resources/views/workspace/home-abos.blade.php'] as $file) {
            $source = (string) file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression('/<p data-home-company data-page-subtitle\b[^>]*>\{\{ \$headNote \}\}<\/p>/', $source,
                "⛔ {$file}: হোমের লাইন ABOS রূপে লুকায় না।");
            $this->assertMatchesRegularExpression('/<h1 data-page-title data-home-title\b[^>]*>.*?<x-ui\.page-info :text="\$headNote" \/><\/h1>/s', $source,
                "⛔ {$file}: একই কথা শিরোনামের ⓘ-তে নেই।");
        }
    }
}
