<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⛔ "টেস্টে বন্ধ" নিয়মটা যেন লাইভে না পৌঁছায়।
 *
 * ── ⓘ কেন এই পাহারাটা লাগল, ২৯ সেপ্টেম্বর ২০২৬ ───────────────────────
 * নতুন কোম্পানিতে সইয়ের ছক বসানো শুরু হয়েছে, আর [[\Database\Seeders\DemoSeeder]]
 * সেই একই পথে ডেমো কোম্পানি বানায়। ⚠️ আজকের শত শত টেস্ট **সই-ছাড়া
 * কোম্পানি** ধরে লেখা, তাই ডেমোতে ছকগুলো বসার পর **বন্ধ** করে রাখা হয়।
 *
 * ⛔ ওটা একটা ধার, সমাধান নয় — আর ধারের বিপদ হলো সে ছড়ায়। আজ সিডারে,
 * কাল একটা সেবায়, পরশু একটা কমান্ডে; আর তারপর একদিন লাইভে টাকার কাগজে
 * কেউ সই চায় না, আর কোনো টেস্ট লাল হয় না।
 *
 * ⭐ [[\Tests\SignsMoneyOff]]-এর মাথায় কথাটা এভাবে লেখা: *"টেস্টে বন্ধ
 * করা যায় এমন নিয়ম লাইভে একদিন বন্ধ থাকে — কনফিগের একটা ভুলে, আর কোনো
 * টেস্ট লাল হয় না, কারণ টেস্টগুলোই তো বন্ধ রেখে চলে।"*
 *
 * ── ⚠️ মন্তব্য কোড নয় ────────────────────────────────────────────────
 * ⓘ একবার একটা পাহারা ডকব্লকের `DB::table(` দেখে লাল হয়েছিল। তাই এখানে
 * মন্তব্য আগে ছেঁটে ফেলা হয়, তারপর গোনা হয়।
 */
final class OnlyTheDemoSeederParksAnApprovalFlowTest extends TestCase
{
    /** ⓘ যে একটামাত্র ফাইলকে ছাড় দেওয়া আছে — নাম ধরে, অনুমানে নয়। */
    private const ALLOWED = 'database/seeders/DemoSeeder.php';

    public function test_nobody_but_the_demo_seeder_switches_an_approval_flow_off(): void
    {
        $guilty = [];
        $looked = 0;

        foreach ($this->sourceFiles() as $file) {
            $looked++;

            $code = $this->withoutComments((string) File::get($file));

            if (! $this->switchesAFlowOff($code, $this->talksAboutFlows($code))) {
                continue;
            }

            $where = $this->relative($file);

            if ($where === self::ALLOWED) {
                continue;
            }

            /*
             * ⭐ যে মডিউল ছকের মালিক, সে ছক সামলাতে পারে — ২৯ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ প্রথমে এই ছাড়টা ছিল না, আর পাহারা
             * [[ApprovalFlowController]] ও [[ApprovalLimitController]]-কে
             * ধরে ফেলেছিল — অথচ ওরা ছক মোছে কারণ **মানুষ মুছতে বলেছেন**।
             * ⓘ ওটা ধার নয়, ওটাই ওদের কাজ।
             *
             * ⚠️ পাহারাটার আসল বিষয় হলো ধারটা **ছড়িয়ে পড়া**: অন্য কোনো
             * সেবা, সিডার বা কমান্ড যদি চুপচাপ ছক সরায় বা বন্ধ করে।
             */
            if (str_starts_with($where, 'app/Modules/Approval/')) {
                continue;
            }

            $guilty[] = $where;
        }

        /*
         * ⛔ কিছু না দেখেও সবুজ হওয়া পাহারাই সবচেয়ে বিপজ্জনক — একবার
         * একটা স্ক্রিপ্ট দশবার "এখানে কিছু নেই" বলেছিল কারণ তার
         * খোঁজাটাই কোনোদিন চলেনি।
         */
        $this->assertGreaterThan(200, $looked,
            'মাত্র '.$looked.'টা ফাইল দেখা হয়েছে — খোঁজাটাই ভেঙেছে, আর তখন '
            .'এই দাবিটা কিছুই মাপে না।');

        $this->assertSame([], $guilty, implode("\n", [
            '⛔ এই ফাইলগুলো সইয়ের ছক বন্ধ করে দেয়:',
            ...$guilty,
            '',
            'ⓘ ছক বন্ধ করা কেবল '.self::ALLOWED.'-এ চলে, আর সেখানেও একটা ধার '
            .'হিসেবে — কারণ আজকের টেস্টগুলো সই-ছাড়া কোম্পানি ধরে লেখা।',
            '⚠️ অন্য কোথাও এটা মানে লাইভে টাকার কাগজে কেউ সই চাইবে না, '
            .'আর কোনো টেস্ট লাল হবে না।',
        ]));
    }

    public function test_the_search_can_actually_find_the_thing_it_forbids(): void
    {
        /*
         * ⭐ পাহারাটা নিজেই একবার ভেঙে দেখা — নাহলে উপরের দাবিটা সবুজ
         * থাকতে পারে কেবল এই কারণে যে সে কিছুই চেনে না।
         */
        $shapes = [
            "DB::table('approval_flows')->where('company_id', 1)->delete();",
            'ApprovalFlow::query()->delete();',
            "DB::table('approval_flows')->update(['is_active' => false]);",
            'ApprovalFlow::query()->update([\'is_active\' => false]);',
            'ApprovalFlow::query()->update(["is_active" => 0]);',
        ];

        foreach ($shapes as $shape) {
            $this->assertTrue(
                $this->switchesAFlowOff($shape, $this->talksAboutFlows($shape)),
                'এই আকৃতিটা পাহারার চোখে পড়ে না: '.$shape,
            );
        }

        /*
         * ⛔ দ্বিতীয় বাহুটা আলাদা করে: ভেরিয়েবলে ধরা একটা মডেল,
         * যে ফাইলে ছকের কথা আছে সেখানে — ঠিক যে আকৃতিটা একবার পালিয়ে
         * গিয়েছিল।
         */
        $hidden = "use App\\Models\\ApprovalFlow;\n\$flow->forceFill(['is_active' => false])->save();";

        $this->assertTrue($this->switchesAFlowOff($hidden, $this->talksAboutFlows($hidden)),
            'ভেরিয়েবলে ধরা মডেল এখনও পালাতে পারে।');

        /*
         * ⛔ ছাড়টা সরু কি না, সেটাও মাপা: Approval মডিউলের **বাইরের**
         * একটা ফাইল একই কাজ করলে ধরা পড়তে হবে।
         */
        $this->assertFalse(
            str_starts_with('app/Modules/Sales/Services/SomeService.php', 'app/Modules/Approval/'),
            'ছাড়টা এত চওড়া যে অন্য মডিউলও পার পেয়ে যাচ্ছে।',
        );

        /* ⓘ আর যা নিষেধ নয়, তাতে যেন লাল না হয় */
        foreach ([
            "ApprovalFlow::query()->update(['is_active' => true]);",
            "DB::table('users')->update(['is_active' => false]);",
        ] as $innocent) {
            $this->assertFalse($this->switchesAFlowOff($innocent),
                'এটায় লাল হওয়ার কথা নয়: '.$innocent);
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ── ⚠️ দুইটা বাহু, আর দ্বিতীয়টা বলে দেওয়া দরকার ────────────
     * ⓘ প্রথম বাহু শক্ত: একই বিবৃতিতে ছকের নাম আর `is_active => false`।
     *
     * ⛔ কিন্তু `$flow->forceFill([...])` লিখলে বিবৃতিতে কোনো টেবিল বা
     * ক্লাসের নাম থাকে না — কেবল একটা ভেরিয়েবল। ⭐ আমার নিজের
     * পাহারা ঠিক ওই আকৃতিটা মিস করেছিল, আর নিজের স্ব-পরীক্ষায় ধরা
     * পড়েছে।
     *
     * ⚠️ তাই দ্বিতীয় বাহু: যে ফাইল সইয়ের ছক নিয়ে কথা বলে, তার **যেকোনো**
     * `is_active => false` সন্দেহজনক। ⓘ এটা অনুমান, প্রমাণ নয় — আর
     * সেটা লেখা থাকাই ভালো। ⛔ স্থির খোঁজা কখনো সম্পূর্ণ হয় না; যে
     * পাহারা নিজেকে সম্পূর্ণ বলে, সেটাই সবচেয়ে বিপজ্জনক।
     */
    private function switchesAFlowOff(string $code, bool $fileTalksAboutFlows = false): bool
    {
        $statements = preg_split('/;/', $code) ?: [];

        foreach ($statements as $statement) {
            $switchesOff = preg_match('/[\'"]is_active[\'"]\s*=>\s*(false|0)\b/i', $statement) === 1;

            /*
             * ⭐ মোছাও একই জিনিস — ২৯ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ প্রথমে কেবল "বন্ধ করা" খোঁজা হত। ⚠️ কিন্তু সিডার এখন ছক
             * **মুছে** ফেলে, আর সেটাও ঠিক একই ধার: ডেমোতে ছক নেই বলে
             * টেস্টগুলো আজকের মতো চলে। ⛔ ঐ ধারটা provisioner বা কোনো
             * সেবায় ছড়ালে ফল একই — লাইভে টাকার কাগজে কেউ সই চাইবে না।
             */
            $deletes = preg_match('/->\s*(delete|forceDelete|truncate)\s*\(/', $statement) === 1;

            if (! $switchesOff && ! $deletes) {
                continue;
            }

            if ($fileTalksAboutFlows) {
                return true;
            }

            if (str_contains($statement, 'approval_flows') || str_contains($statement, 'ApprovalFlow')) {
                return true;
            }
        }

        return false;
    }

    private function talksAboutFlows(string $code): bool
    {
        return str_contains($code, 'approval_flows') || str_contains($code, 'ApprovalFlow');
    }

    private function withoutComments(string $source): string
    {
        $out = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

        return preg_replace('#^\s*//.*$#m', '', $out) ?? $out;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ([base_path('app'), base_path('database'), base_path('routes')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * ⚠️ দুইটা ধাপ, আর ক্রমটা গুরুত্বপূর্ণ: আগে স্ল্যাশ এক রকম করা, তারপর
     * শিকড় কাটা। ⓘ উল্টো করলে উইন্ডোজের `\` থাকা অবস্থায় শিকড়টা মেলে না,
     * আর পুরো পথটাই ফেরত আসে — একবার এসেছেও।
     */
    private function relative(string $path): string
    {
        $slashed = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', base_path()).'/';

        return str_starts_with($slashed, $root)
            ? substr($slashed, strlen($root))
            : $slashed;
    }
}
