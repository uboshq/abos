<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Drill\DrillResolver;
use Tests\TestCase;

/**
 * ⛔ ফোনের চুক্তি কাগজের প্রতিটা ধরন নাম ধরে বলে — a4-এর অনুরোধ, ২৪ অক্টোবর ২০২৬ (অ্যাপ 0.4.49)।
 *
 * <p>খবরের `paper.type` হলো ড্রিলের উৎস-নাম ([[DrillResolver::map()]])। অ্যাপ প্রতিটা নামকে একটা পর্দায় পাঠায়, নয়তো স্পষ্ট
 * বিকল্পে — তাই চুক্তির সারণিতে ([[docs/Contract — ফোনের খবর কোথায় খোলে.md]]) নামগুলো হুবহু থাকতে হবে। নতুন কাগজের ধরন
 * যোগ হলে, বা নাম বদলালে, চুক্তি না বদলালে এখানে লাল — অ্যাপের মানচিত্রে না-জানা নাম কোথাও না গিয়ে আটকে থাকবে না।
 */
final class TheContractNamesEveryPaperTypeTest extends TestCase
{
    public function test_the_contract_table_names_exactly_the_drill_sources(): void
    {
        $files = glob(base_path('docs/Contract*ফোনের*')) ?: [];
        $this->assertCount(1, $files, 'প্রস্তুতিটাই ভুল — ফোনের খবরের চুক্তির ফাইল একটা নয়');
        $text = (string) file_get_contents($files[0]);

        $this->assertSame(1, preg_match('/<!-- paper-types:start -->(.*?)<!-- paper-types:end -->/s', $text, $m),
            'প্রস্তুতিটাই ভুল — চুক্তিতে paper.type-এর সারণির চিহ্ন নেই');
        preg_match_all('/^\| `([^`]+)` \|/m', $m[1], $rows);
        $inContract = $rows[1];
        sort($inContract);

        $inCode = array_map('strval', array_keys(app(DrillResolver::class)->map()));
        sort($inCode);

        $this->assertSame([], array_values(array_diff($inCode, $inContract)),
            '⛔ কোডে কাগজের এই ধরন আছে, চুক্তির সারণিতে নেই — অ্যাপ এই নাম চিনবে না');
        $this->assertSame([], array_values(array_diff($inContract, $inCode)),
            '⛔ চুক্তির সারণিতে এই ধরন আছে, কোডে আর নেই — সারণি পুরনো');
        $this->assertSame(count($inContract), count(array_unique($inContract)), '⛔ চুক্তির সারণিতে একই নাম দুবার');
    }

    /** ⭐ `closed`-এর বাক্য — ফোন আর ওয়েব একই কথা বলে: চুক্তিতে ওয়েবের হুবহু বাংলা আর ইংরেজি (a4, fe, ২৪ অক্টোবর ২০২৬) */
    public function test_the_contract_quotes_the_web_s_words_for_a_closed_notice(): void
    {
        $text = (string) file_get_contents((glob(base_path('docs/Contract*ফোনের*')) ?: [''])[0]);

        foreach (['gone_title', 'gone_body', 'no_access_title', 'no_access_body'] as $key) {
            foreach (['bn', 'en'] as $locale) {
                $words = (string) __('core.notify.'.$key, [], $locale);
                $this->assertStringContainsString($words, $text,
                    "⛔ ওয়েবের core.notify.{$key} ({$locale}) চুক্তিতে হুবহু নেই — ফোন আর ওয়েব আলাদা কথা বলবে");
            }
        }
    }
}
