<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use ReflectionMethod;
use Tests\TestCase;

/**
 * নিয়মটা লেখা ছিল ঠিক সেই পাহারার পাশে, যে পাহারা ওটা মানত না।
 *
 * ── ⓘ যা ঘটল, ২১–২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * `phpunit.xml`-এ `DB_DATABASE=abos_test` হার্ডকোড করা, আর নিয়ম ছিল
 * প্রত্যেক সেশন নিজের নামে একটা নেবে (`abos_test_d1`, `abos_test_8b`)।
 * ⛔ এক রাতে **দুইটা সেশন দুইজনেই ভুলল**, আর দুইজনের নিজের নোটেই
 * কথাটা লেখা ছিল। ফল:
 *
 *     Deadlock found when trying to get lock
 *     Table 'abos_test.companies' doesn't exist
 *
 * ⚠️ দুইটাই মাঝরানে, আর দুইটাই পড়তে হুবহু নিজের কোডের রিগ্রেশনের মতো।
 *
 * ── ⭐ আসল কথাটা ────────────────────────────────────────────────────
 * নিয়মটা [[TestCase]]-এর টীকায় পরিষ্কার লেখাই ছিল, ঠিক পাহারাটার
 * উপরে — অথচ পাহারাটা `abos_test` **দিয়ে শুরু** হলেই ছেড়ে দিত, তাই
 * খালি `abos_test`-ও পার পেত। ⓘ একটা অলিখিত নিয়ম আর কোনো নিয়ম না
 * থাকা যেমন কার্যত এক, **মন্তব্যে লেখা নিয়ম আর বলবৎ না করা নিয়মও
 * কার্যত এক**।
 *
 * ── ⚠️ আর এই ফাইলটা কেন ─────────────────────────────────────────────
 * পাহারাটা নিজে কোনোদিন পরখ হয় না: সে চলে প্রতিটা পরীক্ষার আগে, আর
 * **চুপ করে থাকাই তার স্বাভাবিক অবস্থা**। ⛔ শর্তটা উল্টো করে লিখলে,
 * বা `getenv` ভুল নাম দেখলে, সে চিরকাল চুপ থাকত আর সবকিছু সবুজই
 * দেখাত। তাই এখানে তাকে **বিপজ্জনক নামটা খাইয়ে** দেখা হয় সে সত্যিই
 * না বলে কি না।
 */
final class TheRuleWasWrittenBesideTheGuardThatIgnoredItTest extends TestCase
{
    /**
     * ⭐ সবার ভাগের নামটা দিলে পাহারা না বলে।
     */
    public function test_the_shared_name_is_refused(): void
    {
        $this->assertTrue(
            $this->verdict('abos_test', ci: false),
            'সবার ভাগের `abos_test` পার পেয়ে যাচ্ছে — পাহারাটা কিছুই আটকায় না।',
        );
    }

    /**
     * ⓘ নিজের নামেরটা ছাড় পায় — নাহলে কেউ পরীক্ষাই চালাতে পারত না।
     *
     * ⚠️ এই দাবিটা না থাকলে "সারাই" মানে হত সব নাম আটকে দেওয়া, আর
     * তখন পাহারাটা নিজেই সবচেয়ে বড় বাধা হয়ে দাঁড়াত।
     */
    public function test_a_session_of_its_own_passes(): void
    {
        foreach (['abos_test_d1', 'abos_test_8b', 'abos_test_77'] as $name) {
            $this->assertFalse(
                $this->verdict($name, ci: false),
                "নিজের নামের `{$name}` আটকে যাচ্ছে।",
            );
        }
    }

    /**
     * ⛔ CI-তে পাহারাটা চুপ থাকে।
     *
     * ⓘ সেখানে `abos_test` ক্ষণস্থায়ী কন্টেইনারে একা বসে, কেউ ভাগ করে
     * না। ⚠️ ছাড়টা না দিলে পাহারাটা প্রতিটা বিল্ড লাল করে দিত —
     * অর্থাৎ পাহারা হয়ে দাঁড়াত নিজেই একটা ভাঙা জিনিস।
     */
    public function test_ci_is_left_alone(): void
    {
        $this->assertFalse(
            $this->verdict('abos_test', ci: true),
            'CI-তেও পাহারাটা আটকাচ্ছে — প্রতিটা বিল্ড এতে লাল হবে।',
        );
    }

    /**
     * ⚠️ আর কাজের ডাটাবেজটা এখানেও আলাদা পথে আটকা।
     *
     * ⓘ এই কলটা কেবল "সবার ভাগের কি না" বলে; `abos` থামে নিচের
     * প্রিফিক্স-পরীক্ষায়। দুইটা আলাদা দরজা, আর এই দাবিটা মনে করিয়ে
     * দেয় যে এটা ওটার বিকল্প নয়।
     */
    public function test_this_call_is_not_the_one_that_stops_the_real_database(): void
    {
        $this->assertFalse(
            $this->verdict('abos', ci: false),
            'কাজের ডাটাবেজটা ভুল দরজায় আটকাচ্ছে — তাহলে আসল দরজাটা অপরীক্ষিত থেকে যায়।',
        );

        $this->assertFalse(
            str_starts_with('abos', 'abos_test'),
            'প্রিফিক্স-পরীক্ষাটাই `abos`-কে ছেড়ে দিচ্ছে।',
        );
    }

    /**
     * পাহারাটার রায় — নামটা সবার ভাগের কি না।
     *
     * ⓘ কলটা `private static`, আর সেটাই ঠিক: বাইরের কারও ডাকার জিনিস
     * নয়। ⚠️ কেবল দেখার জন্য ওটাকে `public` করা মানে হত পরীক্ষার
     * সুবিধার জন্য আসল নকশাটা আলগা করা।
     */
    private function verdict(string $database, bool $ci): bool
    {
        $was = [getenv('CI'), getenv('GITHUB_ACTIONS')];

        putenv($ci ? 'CI=true' : 'CI');
        putenv($ci ? 'GITHUB_ACTIONS=true' : 'GITHUB_ACTIONS');

        try {
            $method = new ReflectionMethod(TestCase::class, 'sharedByEverySession');

            return (bool) $method->invoke(null, $database);
        } finally {
            // ⚠️ না ফেরালে এই ফাইলের পরের পরীক্ষাগুলো ভুল পরিবেশে চলত
            putenv($was[0] === false ? 'CI' : 'CI='.$was[0]);
            putenv($was[1] === false ? 'GITHUB_ACTIONS' : 'GITHUB_ACTIONS='.$was[1]);
        }
    }
}
