<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * কোড যে ধাপটার কথা বলে, স্ক্রিপ্টে সেটা সত্যিই আছে।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * [[PostMissingOpenings]]-এর ব্যাখ্যায় লেখা ছিল *"তাই `deploy.sh`-এ
 * মাইগ্রেশনের ঠিক পরেই"* — আর **রিপোতে একটাও `.sh` ফাইল ছিল না।**
 * ডিপ্লয় মানে ছিল হাতে `git pull` আর `artisan migrate`, মনে থাকলে।
 *
 * কমান্ডটা না চললে যে খাতগুলোর জের ঘোষিত আছে সেগুলোর ব্যালেন্স কমে
 * যায়। কেউ টের পেত না, কারণ সংখ্যাটা ভুল হত নীরবে।
 *
 * ── কেন পাহারাটা ফাইলের লেখা পড়ে ─────────────────────────────────────
 * স্ক্রিপ্টটা এখানে চালানো যায় না — সে সার্ভারে `git pull` করে, npm
 * বিল্ড করে, ডাটাবেজ ছোঁয়। তাই যা যাচাই করা যায় তা-ই করা হয়: **ধাপটা
 * অন্তত লেখা আছে কি না।** এটা প্রমাণ করে না যে ডিপ্লয় কাজ করবে; এটা
 * প্রমাণ করে যে কেউ ধাপটা চুপচাপ ফেলে দেয়নি।
 *
 * দুর্বল পাহারা, কিন্তু ঠিক যে ভুলটা ঘটেছিল সেটাই ধরে — আর অনুপস্থিত
 * পাহারার চেয়ে দুর্বল পাহারা ভালো।
 */
class TheDeployScriptDoesWhatTheCodeClaimsTest extends TestCase
{
    /** ডিপ্লয়ের স্ক্রিপ্টটা আছে। */
    public function test_the_deploy_script_exists(): void
    {
        $this->assertFileExists(
            base_path('infra/deploy.sh'),
            'infra/deploy.sh নেই — অথচ কোডের ব্যাখ্যাগুলো ধরে নিয়েছে আছে।',
        );
    }

    /**
     * প্রতিটা দাবি করা ধাপ স্ক্রিপ্টে আছে।
     *
     * তালিকাটা ইচ্ছে করে ছোট — কেবল সেগুলো যেগুলো **না চললে ডাটা ভুল
     * হয়** (জের, অনুমতি, স্কিমা), আর সেগুলো যেগুলো **ভাঙা ডিপ্লয়
     * ধরে ফেলে** (ব্যাকআপ, খাতা মেলানো, /up দেখা)। সাজানোর ধাপগুলো
     * এখানে নেই, কারণ ওগুলো বদলাতে পারে আর তখন পাহারাটা কেবল
     * বিরক্তিকর হত।
     */
    public function test_every_step_the_code_relies_on_is_in_the_script(): void
    {
        $script = (string) file_get_contents(base_path('infra/deploy.sh'));

        $steps = [
            'abos:backup' => 'ব্যাকআপ ছাড়া ফেরার পথ নেই',
            'migrate --force' => 'স্কিমা না গেলে কোড আর ডাটাবেজ আলাদা থাকে',
            'abos:post-missing-openings' => 'PostMissingOpenings নিজেই এই ধাপটার দাবি করে',
            'abos:sync-permissions' => 'নতুন অনুমতি না বসলে লাইভ পর্দা ৪০৩ দেয়',
            'abos:optimise' => 'ক্যাশ পুরনো থাকলে নতুন রুট পাওয়া যায় না',
            'abos:books-check' => 'খাতা না মিললে ডিপ্লয়েই জানা দরকার',
            'abos:restore' => 'ফিরতে না পারলে এটা ডিপ্লয় স্ক্রিপ্ট নয়, শুধু pull',
            '/up' => 'সাইট উঠেছে কি না না দেখলে ভাঙা ডিপ্লয় সফল বলে শেষ হয়',
        ];

        $missing = [];

        foreach ($steps as $needle => $why) {
            if (! str_contains($script, $needle)) {
                $missing[] = "$needle — $why";
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'infra/deploy.sh-এ এই ধাপগুলো নেই:',
            '',
            implode("\n", $missing),
        ]));
    }

    /**
     * ব্যর্থ হলে স্ক্রিপ্টটা নিজে ফেরে।
     *
     * `set -e` ছাড়া একটা ধাপ ব্যর্থ হলেও পরেরগুলো চলত, আর শেষে
     * "সম্পন্ন" লেখা আসত — অর্থাৎ স্ক্রিপ্টটা মিথ্যা বলত।
     */
    public function test_the_script_stops_and_returns_on_failure(): void
    {
        $script = (string) file_get_contents(base_path('infra/deploy.sh'));

        $this->assertStringContainsString('set -euo pipefail', $script,
            'একটা ধাপ ব্যর্থ হলেও স্ক্রিপ্টটা এগিয়ে যাবে।');

        $this->assertStringContainsString('trap rollback ERR', $script,
            'ব্যর্থতায় ফেরার কোনো ব্যবস্থা নেই।');
    }
}
