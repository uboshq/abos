<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⭐ দলের "phpstan পরিষ্কার" দাবির একটাই command — `vendor/bin/phpstan analyse -c phpstan.l1.neon --memory-limit=2G --no-progress`,
 * exit 0 (fe, ২২ অক্টোবর ২০২৬)। এই পাহারা দেখে config-টা আছে, level 1-এ, নিজের baseline নিয়ে, আর অচল ছাড় ধরার সুইচ চালু;
 * আর পথ, মাইগ্রেশন ও PHP সংস্করণ phpstan.neon-এর সাথে মেলে — দুটো আলাদা হয়ে গেলে "পরিষ্কার" অন্য কোডের কথা বলত।
 */
final class NoStaticAnalysisDriftTest extends TestCase
{
    public function test_the_level_one_config_is_there_and_says_level_one_with_its_own_baseline(): void
    {
        $l1 = $this->read('phpstan.l1.neon');

        $this->assertMatchesRegularExpression('/^\s*level:\s*1\s*$/m', $l1, '⛔ phpstan.l1.neon level 1-এ নয়');
        $this->assertMatchesRegularExpression('/^\s*-\s*phpstan-baseline-l1\.neon\s*$/m', $l1, '⛔ phpstan.l1.neon নিজের baseline নেয় না');
        $this->assertDoesNotMatchRegularExpression('/^\s*-\s*phpstan-baseline\.neon\s*$/m', $l1, '⛔ level 1-এ level 5-এর baseline — শত শত সারি "মেলেনি" হত');
        $this->assertMatchesRegularExpression('/^\s*reportUnmatchedIgnoredErrors:\s*true\s*$/m', $l1, '⛔ অচল ছাড় ধরা বন্ধ — baseline আবার পুরনো হবে');
        $this->assertFileExists(base_path('phpstan-baseline-l1.neon'), '⛔ level 1-এর baseline ফাইল নেই');
    }

    public function test_the_two_configs_read_the_same_code_on_the_same_php(): void
    {
        $main = $this->settings($this->read('phpstan.neon'));
        $l1 = $this->settings($this->read('phpstan.l1.neon'));

        $this->assertNotSame([], $main['paths'], 'প্রস্তুতিটাই ভুল — phpstan.neon-এর পথ পড়া গেল না');
        $this->assertSame($main, $l1, '⛔ phpstan.neon আর phpstan.l1.neon আলাদা কোড বা আলাদা PHP দেখে');
    }

    private function read(string $file): string
    {
        $this->assertFileExists(base_path($file), '⛔ '.$file.' নেই');

        return (string) file_get_contents(base_path($file));
    }

    /**
     * পথ, মাইগ্রেশনের পথ আর PHP সংস্করণ — মন্তব্য বাদে।
     *
     * @return array{paths: list<string>, migrations: list<string>, php: list<string>}
     */
    private function settings(string $neon): array
    {
        $lines = array_values(array_filter(array_map('rtrim', preg_split('/\R/', $neon) ?: []), fn (string $l) => ! preg_match('/^\s*(#|$)/', $l)));
        $block = function (string $key) use ($lines): array {
            $out = [];
            $in = false;
            foreach ($lines as $line) {
                if (preg_match('/^(\s*)'.preg_quote($key, '/').':\s*$/', $line)) {
                    $in = true;

                    continue;
                }
                if ($in && preg_match('/^\s{8}\S/', $line)) {
                    $out[] = trim($line);

                    continue;
                }
                $in = false;
            }

            return $out;
        };

        return ['paths' => $block('paths'), 'migrations' => $block('databaseMigrationsPath'), 'php' => $block('phpVersion')];
    }
}
