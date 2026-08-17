<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Composer\Semver\Semver;
use Tests\TestCase;

/**
 * যে PHP-তে চলবে বলা আছে, সেখানেই লক ফাইলটা বসতে হবে।
 *
 * ── কী ঘটেছিল ───────────────────────────────────────────────────────
 * `composer.json` বলে `"php": "^8.3"` — অর্থাৎ ৮.৩, ৮.৪, ৮.৫ সবই চলবে।
 * Mac mini-তে PHP ৮.৫। কিন্তু লক ফাইলে বাঁধা ছিল `phpspreadsheet 1.30.6`,
 * যেটা নিজে বলে `<8.5.0`। ফলে ওই মেশিনে **`composer install` আদৌ চলত
 * না** — নির্ভরতা বসাতে গেলেই থেমে যেত।
 *
 * সবচেয়ে খারাপ দিকটা হলো এটা নীরব: `vendor/` ফোল্ডারটা আগের ডিপ্লয়ের
 * থেকে পড়ে থাকে, অ্যাপ চলতেই থাকে, আর ডিপ্লয় স্ক্রিপ্টের লেজে একটা
 * সতর্কবাণী ভেসে যায়। যেদিন নতুন কোনো প্যাকেজ লাগবে, সেদিন হঠাৎ জানা
 * যাবে মাসখানেক ধরে নির্ভরতা কিছুই বসছিল না।
 *
 * ── এই টেস্টটা কী করে ───────────────────────────────────────────────
 * লক ফাইলের প্রতিটা প্যাকেজের `php` শর্ত পড়ে, আর দেখে আমরা যে যে
 * সংস্করণে চলার দাবি করি তার প্রতিটাতে শর্তটা মেলে কি না। মেলে না মানে
 * ওই সংস্করণের মেশিনে ডিপ্লয় ভাঙা — আর সেটা এখানেই ধরা পড়ে, সার্ভারে
 * নয়।
 */
class TheLockMustInstallWherePhpRunsTest extends TestCase
{
    /**
     * যে সংস্করণগুলোতে চলার দাবি করা হয়।
     *
     * পরিসর ধরে গোনা যায় না — সেমভার পরিসর অসীম। তাই সত্যিকারের যে
     * সংস্করণগুলো কোথাও না কোথাও চলছে, সেগুলোই ধরে দেখা হয়: Zenbook,
     * HP ও Mac mini তিনটার তিন রকম PHP, আর ওটাই আসল ঝুঁকি।
     */
    private const RUNS_ON = ['8.3.0', '8.4.0', '8.5.0', '8.5.9'];

    public function test_every_locked_package_accepts_every_php_we_claim_to_run_on(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);

        $this->assertIsArray($lock['packages'] ?? null, 'composer.lock পড়া গেল না।');

        $declared = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $range = $declared['require']['php'] ?? null;

        $this->assertNotNull($range, 'composer.json কোনো PHP সংস্করণই দাবি করে না।');

        $offenders = [];

        foreach ([...$lock['packages'], ...($lock['packages-dev'] ?? [])] as $package) {
            $needs = $package['require']['php'] ?? null;

            if ($needs === null) {
                continue;
            }

            foreach (self::RUNS_ON as $php) {
                if (! Semver::satisfies($php, $range)) {
                    // এই সংস্করণটা আমরা দাবিই করি না — এটার দায় নেই
                    continue;
                }

                if (! Semver::satisfies($php, $needs)) {
                    $offenders[] = "{$package['name']} ({$needs}) — PHP {$php}-এ বসবে না";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            "লক ফাইলটা এমন মেশিনে বসবে না যেখানে চলার দাবি করা হয়েছে:\n"
            .implode("\n", array_unique($offenders))
            ."\n\nহয় প্যাকেজটা তোলো, নয় composer.json-এর দাবিটা ছোট করো — "
            .'দুইটার একটা না করলে ওই মেশিনে `composer install` চুপচাপ থেমে থাকবে।');
    }
}
