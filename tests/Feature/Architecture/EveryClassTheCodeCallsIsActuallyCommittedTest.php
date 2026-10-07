<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\Money;
use Tests\TestCase;

/**
 * কমিট করা কোড যে ক্লাসগুলো ডাকে, সেগুলোও কমিট করা আছে কি না।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ⛔ ঐ রাতে লাইভে **লগইন করা প্রতিটা পাতা** ভেঙে পড়ে। কারণ:
 * `AppServiceProvider` আর `UserPermissionOverride` দুইটাই
 * `App\Core\Services\PermissionOverrides` ডাকত, দুইটাই কমিট হয়েছিল —
 * আর **ক্লাসটা নিজে untracked** ছিল। ডিস্কে আছে, গিটে নেই।
 *
 * ⚠️ এখানে কিছু ভাঙেনি, কারণ ফাইলটা আমাদের মেশিনে আছে। তাই পরীক্ষা
 * সবুজ, উন্নয়ন সবুজ, আর লাইভ মৃত — সবচেয়ে খারাপ সংমিশ্রণ। ⓘ লগইনের
 * পাতাটা তবু ২০০ দিচ্ছিল, তাই বাইরে থেকে সাইটটা সচল দেখাচ্ছিল।
 *
 * ── ⓘ কেন `git status` যথেষ্ট নয় ───────────────────────────────────
 * untracked ফাইল আলাদা ব্লকে থাকে, আর `git add -- <paths>` সেই নামটা
 * না বললে **নীরবে** বাদ পড়ে। ⭐ মানুষ ভুল করবেই; যন্ত্রটাকে দেখতে হবে।
 *
 * ── ⚠️ এটা কী মাপে না ──────────────────────────────────────────────
 * ক্লাসটা কাজ করে কি না তা নয় — কেবল **গিট ওটাকে চেনে কি না**। আর
 * ভুলটা ঠিক ঐ আকারেই হয়েছিল।
 */
final class EveryClassTheCodeCallsIsActuallyCommittedTest extends TestCase
{
    public function test_no_committed_file_calls_a_class_git_has_never_seen(): void
    {
        $tracked = $this->trackedAppFiles();

        /*
         * ⚠️ আগে এটা: গিটের তালিকা না পেলে নিচের লুপ সব ক্লাসকেই
         * "নেই" বলত, অথবা খালি তালিকায় সব পাশ করত — দুইটাই মিথ্যা।
         */
        $this->assertGreaterThan(
            500,
            count($tracked),
            'গিট থেকে মাত্র '.count($tracked).'টা ফাইল পাওয়া গেল — তালিকাটাই ভেঙেছে।'
        );

        $missing = [];

        /*
         * ⚠️ `array_keys` — তালিকাটা পাথ-কে **চাবি** করে রাখে, আর
         * `foreach ($tracked as $relative)` ঘুরত মান ধরে, অর্থাৎ প্রতিটা
         * নাম হয়ে যেত `true`।
         *
         * ⛔ তাতে `file_get_contents` সবসময় ব্যর্থ হত, লুপটা প্রতিবার
         * `continue` করত, আর পাহারাটা **কখনো কিছু দেখত না** — ঠিক ঐ
         * রোগ যেটা ধরতে সে লেখা। ⓘ ধরা পড়েছে ইচ্ছে করে একটা ফাইল
         * untrack করে: সে তবু সবুজ ছিল।
         */
        foreach (array_keys($tracked) as $relative) {
            $src = @file_get_contents(base_path($relative));

            if ($src === false) {
                continue;
            }

            foreach ($this->appClassesUsedIn($src) as $class) {

                /*
                 * ⛔ শর্তটা দুইটা, আর দুইটাই লাগে:
                 *   · গিট ফাইলটা চেনে না, আর
                 *   · ডিস্কে ফাইলটা **আছে**
                 *
                 * ⓘ দ্বিতীয়টা ছাড়া এই পাহারা ভেন্ডরের ক্লাস, ইন্টারফেস
                 * বা একই ফাইলে লেখা ক্লাস নিয়েও চিৎকার করত। ⚠️ আর যে
                 * ফাইল ডিস্কেও নেই সেটা এখানেই ভাঙত — লাইভের অপেক্ষা
                 * করতে হত না।
                 */
                if ($this->onDiskButNotInGit($class, $tracked)) {
                    $missing[] = $class.'  ← '.$relative;
                }
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['⛔ কমিট করা ফাইল এমন ক্লাস ডাকছে যা গিটে নেই:', ''],
            $missing,
            ['',
                'ⓘ ফাইলগুলো ডিস্কে আছে, তাই এখানে সব চলে — লাইভে চলবে না।',
                '',
                'চালান: git add -- <ঐ ফাইলগুলোর পাথ>  তারপর কমিটে যোগ করুন।']
        )));
    }

    /**
     * **কমিট হয়ে যাওয়া** `app/` ফাইলগুলো — HEAD থেকে, ইনডেক্স থেকে নয়।
     *
     * ── ⛔ কেন HEAD, আর `git ls-files` কেন নয় ───────────────────────
     * প্রথম খসড়ায় `ls-files` ছিল, আর সেটা **ইনডেক্স** পড়ে। ⚠️ ইনডেক্স
     * একটা খসড়ার জায়গা: চারটা সেশন একই গাছে কাজ করে, আর ২১ সেপ্টেম্বর
     * ২০২৬-এ ওখানে ৯৭টা ফাইলের পুরনো ছায়া বসে ছিল।
     *
     * ⛔ ফলে পাহারাটা দুইটা ফাইলকে "গিটে নেই" বলেছিল যেগুলো আসলে HEAD-এ
     * আছে আর লাইভেও পৌঁছেছে — মিথ্যা অভিযোগ, আর মিথ্যা অভিযোগ করা
     * পাহারা কয়েক দিনেই বন্ধ করে দেওয়া হয়।
     *
     * ⭐ প্রশ্নটা আসলে "ফাইলটা **পুশ হয়ে যাবে** কি না", আর তার উত্তর
     * HEAD-এ, ইনডেক্সে নয়।
     *
     * @return array<string, true>
     */
    /**
     * ⭐ সিদ্ধান্তটা এক জায়গায় — সুইপ আর নিজের পরীক্ষা দুইটাই এটাই ডাকে।
     *
     * ⚠️ আলাদা করা হয়েছে যাতে নিচের পরীক্ষাটা **আসল যুক্তিটাই** চালাতে
     * পারে, তার একটা নকল নয় — নকল হলে দুইটা আলাদা হয়ে যেত আর পরীক্ষা
     * সবুজ থেকেও সুইপ অন্ধ হত।
     *
     * @param  array<string, bool>  $tracked
     */
    private function onDiskButNotInGit(string $class, array $tracked): bool
    {
        $path = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';

        return ! isset($tracked[$path]) && is_file(base_path($path));
    }

    /**
     * ⭐ পাহারাটা সত্যিই একটা ধরতে পারে — ২২ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ কেন এটা লাগল ─────────────────────────────────────────────
     * এই পাহারাটা **দুইটা ডিপ্লয়-গেটেই** আছে, অথচ কেউ কোনোদিন মাপেনি
     * সে কিছু ধরতে পারে কি না। ⚠️ উপরের ফাইল-গোনাটা **খালি জাল** ধরে,
     * **ছেঁড়া জাল** নয়: `appClassesUsedIn()`-এর প্যাটার্ন ভাঙলে সুইপ
     * ছয়শো ফাইল পড়ে, কিছু পায় না, আর সবুজ হয়।
     *
     * ⓘ তাই একটা সত্যিকারের কমিট করা ক্লাস নিয়ে তালিকা থেকে নামটা
     * তুলে নেওয়া হয় — ঠিক যেন সেটা কমিট করা হয়নি — আর দেখা হয়
     * পাহারাটা তাকে ধরে কি না।
     */
    public function test_the_guard_catches_a_class_that_is_not_in_git(): void
    {
        $tracked = $this->trackedAppFiles();

        $this->assertNotSame([], $tracked, 'গিট থেকে কিছুই পড়া গেল না — পরীক্ষাটা কিছু মাপছে না।');

        $class = Money::class;
        $path = 'app/Core/Support/Money.php';

        $this->assertArrayHasKey($path, $tracked, 'Money.php গিটে নেই — নমুনাটাই ভুল।');

        $this->assertFalse($this->onDiskButNotInGit($class, $tracked),
            'কমিট করা একটা ক্লাসকে পাহারা অপরাধী বলছে — তাহলে প্রতিটা রান লাল হত।');

        // ⓘ ঠিক যেন কেউ ফাইলটা কমিট করতে ভুলে গেছে
        unset($tracked[$path]);

        $this->assertTrue($this->onDiskButNotInGit($class, $tracked), implode(PHP_EOL, [
            'একটা ক্লাস ডিস্কে আছে অথচ গিটে নেই, আর পাহারা সেটা ধরতে পারল না।',
            '',
            'অর্থাৎ ডিপ্লয়ের দুইটা গেটই এই প্রশ্নে অন্ধ — লাইভে সাদা পর্দা',
            'আসবে, আর এখানে সব সবুজ থাকবে।',
        ]));
    }

    /**
     * ⭐ `use` লাইন থেকে ক্লাসের নাম তোলাটাও প্রমাণ করা দরকার।
     *
     * ⚠️ এটাই আসল জাল: প্যাটার্নটা ভাঙলে সুইপের কাছে প্রতিটা ফাইল
     * নিরপরাধ মনে হবে। ⓘ আর ভাঙাটা নীরব — একটা `\\` কম বা বেশি হলেই যথেষ্ট।
     */
    public function test_the_reader_actually_finds_the_classes_a_file_uses(): void
    {
        $src = implode(PHP_EOL, [
            '<?php',
            'namespace App\\Demo;',
            'use App\\Core\\Support\\Money;',
            'use App\\Modules\\Sales\\Models\\SalesOrder;',
            'use Illuminate\\Support\\Str;',
            'use App\\Core\\Support\\Money as Taka;',
        ]);

        $found = $this->appClassesUsedIn($src);

        $this->assertContains('App\\Core\\Support\\Money', $found,
            '`use` লাইন থেকে ক্লাসের নামই তোলা যাচ্ছে না — জালটা ছেঁড়া।');

        $this->assertContains('App\\Modules\\Sales\\Models\\SalesOrder', $found,
            'মডিউলের ক্লাস তোলা যাচ্ছে না।');

        /*
         * ⓘ অ্যাপের বাইরের ক্লাস বাদ — নাহলে প্রতিটা `Illuminate\\…`
         * অপরাধী গণ্য হত আর তালিকাটা পড়ার অযোগ্য হয়ে যেত।
         */
        $this->assertNotContains('Illuminate\\Support\\Str', $found,
            'অ্যাপের বাইরের ক্লাসও তোলা হচ্ছে।');
    }

    private function trackedAppFiles(): array
    {
        $out = [];

        exec('git -C '.escapeshellarg(base_path()).' ls-tree -r --name-only HEAD app 2>&1', $lines, $code);

        if ($code !== 0) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_ends_with($line, '.php')) {
                $out[$line] = true;
            }
        }

        return $out;
    }

    /**
     * ফাইলটা `App\…` কোন ক্লাসগুলো নাম ধরে ডাকে।
     *
     * ⓘ কেবল `use App\…;` — ওটাই একমাত্র জায়গা যেখানে পুরো নামটা
     * নিশ্চিতভাবে লেখা থাকে, আর মন্তব্যের ভিতরের `[[App\…]]` লিংকগুলো
     * এতে পড়ে না।
     *
     * @return list<string>
     */
    private function appClassesUsedIn(string $src): array
    {
        preg_match_all('/^use\s+(App\\\\[A-Za-z0-9_\\\\]+)\s*;/m', $src, $m);

        return $m[1];
    }
}
