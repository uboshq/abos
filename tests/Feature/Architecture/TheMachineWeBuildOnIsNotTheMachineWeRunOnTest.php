<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * যে মেশিনে লেখা হয় আর যে মেশিনে চলে — দুইটা এক নয়।
 *
 * ── কী ধরা পড়েছিল, ১৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * মালিকের ল্যাপটপে **PHP ৮.৫.১০**, আর লাইভ cPanel-এ **৮.৩**।
 *
 * ⓘ CI দুই জায়গাতেই ৮.৩ চালায়, তাই ৮.৪/৮.৫-এর সিনট্যাক্স ব্যবহার করলে
 * গেট লাল হয় — সেই দিকটা ঢাকা ছিল।
 *
 * ⛔ কিন্তু `composer.json`-এ platform পিন ছিল না। ফল: ঐ ল্যাপটপে
 * `composer update` চালালে Composer ভাবত তার কাছে ৮.৫ আছে, আর এমন
 * প্যাকেজ বাছতে পারত যেটা ৮.৪ চায়। ⚠️ সেটা `composer.lock`-এ বসত, আর
 * ভাঙত **লাইভ সার্ভারে `composer install` করার সময়** — অর্থাৎ ডিপ্লয়ের
 * মাঝপথে, সবচেয়ে খারাপ মুহূর্তে।
 *
 * ── ⭐ কেন পিনটাই সঠিক উত্তর, "সবাই ৮.৩ বসাও" নয় ─────────────────────
 * প্রত্যেকের মেশিনে একই PHP বসানো একটা **মানুষের উপর দাঁড়ানো নিয়ম**, আর
 * ঐ ধরনের নিয়ম একদিন কেউ ভাঙে। পিনটা যন্ত্রের: Composer নিজেই লাইভের
 * ভান করে, তাই ভুল প্যাকেজটা বাছাই হয় না।
 *
 * ── ⚠️ আর এই পাহারাটা কেন লাগে ──────────────────────────────────────
 * পিন আর CI-র সংস্করণ **দুইটা আলাদা জায়গায়** লেখা। একটা বদলে অন্যটা না
 * বদলালে কিছুই ভাঙে না — কেবল ছাঁকনিটা আর লাইভের সাথে মেলে না, আর
 * সেটা টের পাওয়া যায় ডিপ্লয়ের দিন। ⓘ আজকের চেনা আকৃতি: নিয়ম লেখা,
 * অথচ কেউ মিলিয়ে দেখে না।
 */
final class TheMachineWeBuildOnIsNotTheMachineWeRunOnTest extends TestCase
{
    /**
     * ⛔ Composer লাইভের PHP-র ভান করে, এই মেশিনের নয়।
     */
    public function test_composer_resolves_against_the_live_php_version(): void
    {
        $composer = $this->composer();

        $pinned = $composer['config']['platform']['php'] ?? null;

        $this->assertIsString($pinned, implode("\n", [
            'composer.json-এ `config.platform.php` নেই।',
            '',
            '⛔ ছাড়া চললে `composer update` এই মেশিনের PHP ধরে প্যাকেজ বাছে।',
            'লাইভ সার্ভারের PHP পুরনো হলে ঐ lock ফাইলটা ওখানে বসবেই না —',
            'আর সেটা ধরা পড়বে ডিপ্লয়ের মাঝপথে।',
        ]));

        $this->assertSame($this->ciPhpVersion(), $this->minorOf($pinned), implode("\n", [
            'composer.json-এর platform পিন আর CI-র php-version আলাদা।',
            '',
            'পিন    : '.$pinned,
            'CI     : '.$this->ciPhpVersion(),
            '',
            '⚠️ দুইটাই লাইভ সার্ভারের সংস্করণ হওয়ার কথা। আলাদা হলে একটা',
            'ছাঁকনি অন্যটার চেয়ে ঢিলা, আর ঢিলাটার ভুল ধরা পড়ে লাইভে।',
        ]));
    }

    /**
     * ⚠️ `require.php` পিনের চেয়ে নতুন কিছু দাবি করে না।
     *
     * ⓘ দুইটা একসাথে বেমানান হলে Composer নিজেই থামত — কিন্তু কেবল
     * `update`-এর সময়, আর সেই বার্তাটা পড়ে বোঝা কঠিন। এখানে প্রশ্নটা
     * সরাসরি করা হয়।
     */
    public function test_the_required_php_allows_the_pinned_one(): void
    {
        $composer = $this->composer();

        $required = (string) ($composer['require']['php'] ?? '');
        $pinned = (string) ($composer['config']['platform']['php'] ?? '');

        $this->assertNotSame('', $required);
        $this->assertNotSame('', $pinned);

        $floor = ltrim(explode('|', $required)[0], '^~>= ');

        $this->assertTrue(version_compare($pinned, $floor, '>='), implode("\n", [
            "require.php বলে `{$required}`, অথচ পিন করা আছে `{$pinned}` —",
            'অর্থাৎ পিনটা নিজের দাবির চেয়েও পুরনো। Composer কিছুই বসাতে পারবে না।',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $raw = File::get(base_path('composer.json'));
        $parsed = json_decode($raw, true);

        $this->assertIsArray($parsed, 'composer.json বৈধ JSON নয়।');

        return $parsed;
    }

    /**
     * CI যে PHP-তে চলে — ফাইল থেকে পড়া, মুখস্থ নয়।
     *
     * ⚠️ একাধিক জায়গায় লেখা থাকলে সবগুলো এক হতে হবে; নাহলে একটা কাজ
     * এক সংস্করণে যাচাই হত আর অন্যটা অন্যটায়, আর কোনটা লাইভের মতো তা
     * কেউ বলতে পারত না।
     */
    private function ciPhpVersion(): string
    {
        $workflow = base_path('.github/workflows/ci.yml');

        $this->assertFileExists($workflow);

        preg_match_all('/php-version:\s*"?([0-9]+\.[0-9]+)"?/', File::get($workflow), $found);

        $versions = array_values(array_unique($found[1]));

        $this->assertNotEmpty($versions,
            'ci.yml-এ একটাও php-version পাওয়া গেল না — খোঁজাটা কি আর কাজ করছে?');

        $this->assertCount(1, $versions,
            'CI একাধিক PHP সংস্করণে চলছে: '.implode(', ', $versions)
            .' — কোনটা লাইভের মতো তা আর বলা যায় না।');

        return $versions[0];
    }

    private function minorOf(string $version): string
    {
        $bits = explode('.', $version);

        return ($bits[0] ?? '0').'.'.($bits[1] ?? '0');
    }
}
