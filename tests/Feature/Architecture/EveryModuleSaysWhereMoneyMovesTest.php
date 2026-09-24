<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use Tests\TestCase;

/**
 * যে মডিউল অনুমোদন চায়, সে বলবে কোন কাজে টাকা নড়ে।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত ৫, ২৪ সেপ্টেম্বর ২০২৬ ────────────────────────
 * *"টাকা নড়ার কাজে একসাথে সই দেওয়া যাবে না"* — পরিশোধ, উত্তোলন, টাকা
 * স্থানান্তর, আদায়, বছর বন্ধ। ⓘ ওগুলো একটা একটা করে খুলে দেখতে হবে।
 *
 * ── ⛔ কেন এই পাহারাটা লাগল ──────────────────────────────────────────
 * ⓘ [[BulkApproval::movesMoney()]] উত্তরটা **মডিউলের কাছে** চায়
 * (`module.php`-এর `moves_money`), কারণ এই ফাইলে একটা হাতে লেখা তালিকা
 * রাখলে নতুন একটা টাকার কাজ যোগ হলে কেউ এখানে বসাতে ভুলে যেত।
 *
 * ⚠️ কিন্তু উল্টো ভুলটাও সমান নীরব: চাবিটাই না থাকলে `?? []` খায়, আর
 * ঐ মডিউলের **প্রতিটা কাজ** bulk-এ ঢুকে পড়ে। ⛔ কোনো ত্রুটি নেই, পর্দায়
 * চেকবক্সটা আঁকা হয়, আর একটা পরিশোধ একসাথে সইয়ে পাশ হয়ে যায়।
 *
 * ── ⚠️ আর এটা বাস্তবে একবার প্রায় ঘটেছে ─────────────────────────────
 * ⓘ `app/Modules/Purchase/module.php`-এ আমার লাইনগুলো বসেছে, কিন্তু
 * ফাইলটায় আরেকটা সেশনের অসংরক্ষিত কাজ মিশে আছে — তাই আমি ওটা কমিট
 * করতে পারিনি। ⛔ ঐ লাইনগুলো হারিয়ে গেলে ক্রয়ের পরিশোধ ও বিল নীরবে
 * bulk-এ ঢুকে যেত। ⭐ এই দাবিটা তখন লাল হয়, চুপ থাকে না।
 */
class EveryModuleSaysWhereMoneyMovesTest extends TestCase
{
    /**
     * যে মডিউলগুলোতে সত্যিই টাকা নড়ে — আর তাদের কোন কাজে।
     *
     * ── ⚠️ কেন তালিকাটা এখানে **হাতে লেখা**, রেজিস্ট্রি থেকে নয় ────────
     * ⛔ রেজিস্ট্রি থেকে পড়লে দাবিটা নিজেকেই মেলাত — `module.php` যা
     * বলে, টেস্টও তাই বলত, আর একটা কাজ বাদ পড়লে **দুই দিকেই** বাদ পড়ত।
     * ⓘ সেটা একটা সবুজ পাহারা যা কখনো কিছু দেখে না
     * ([[never-supply-the-name-yourself]])।
     *
     * ⚠️ তাই নামগুলো এখানে আলাদা করে লেখা, আর দুই দিক মিলিয়ে দেখা হয়।
     * ⓘ নতুন একটা টাকার কাজ এলে **দুই জায়গায়** বসাতে হবে — সেটাই উদ্দেশ্য:
     * একটা ভুলে গেলে অন্যটা চিৎকার করে।
     *
     * @var array<string, list<string>>
     */
    private const MONEY = [
        'finance' => ['withdrawal'],

        'accounts' => [
            'expense', 'counter_deposit', 'counter_payment', 'transfer',
            'receipt', 'payment', 'journal', 'contra', 'year_end',
        ],

        'purchase' => ['payment', 'bill'],

        'sales' => ['collection'],

        'hr' => ['payroll'],
    ];

    public function test_every_money_action_is_declared_on_its_module(): void
    {
        $registry = app(ModuleRegistry::class);

        foreach (self::MONEY as $code => $actions) {
            $module = $registry->get($code);

            $this->assertNotNull($module, '⛔ মডিউলটাই নেই: '.$code);

            foreach ($actions as $action) {
                $this->assertContains($action, $module->movesMoney, implode("\n", [
                    'টাকা নড়ে এমন একটা কাজ `moves_money`-তে নেই — '.$code.'.'.$action,
                    '',
                    '⛔ ফলটা নীরব: চাবিটা না থাকলে `?? []` খায়, পর্দায় বাছাইয়ের ঘর',
                    '   আঁকা হয়, আর ঐ কাগজটা একসাথে সইয়ে পাশ হয়ে যায়।',
                    '',
                    'ⓘ সারানোর পথ: `app/Modules/'.ucfirst($code).'/module.php`-এ',
                    "   'moves_money' => [… '".$action."' …] বসান।",
                ]));
            }
        }
    }

    /**
     * ⛔ আর যে মডিউল অনুমোদন চায়, সে চাবিটা **ঘোষণা** করবে — খালি হলেও।
     *
     * ── ⚠️ কেন খালি ঘোষণাও দরকার ────────────────────────────────────
     * ⓘ চাবি না থাকা আর `'moves_money' => []` — দুইটা কোডে এক, কিন্তু
     * **মানুষের কাছে এক নয়**: প্রথমটা মানে কেউ ভেবে দেখেনি, দ্বিতীয়টা
     * মানে কেউ দেখে বলেছে *"এই মডিউলে টাকা নড়ে না"*।
     *
     * ⛔ পার্থক্যটা ছেড়ে দিলে একটা নতুন মডিউল অনুমোদন চাইত, কেউ চাবিটা
     * বসাতে ভুলে যেত, আর তার সব কাজ নীরবে bulk-এ ঢুকে যেত।
     */
    public function test_a_module_that_asks_for_approval_says_so_out_loud(): void
    {
        $missing = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            if ($module->approvals === []) {
                continue;   // ⓘ অনুমোদনই চায় না — বলার কিছু নেই
            }

            /*
             * ⚠️ প্রশ্নটা `raw`-এ, `$movesMoney`-তে নয়।
             *
             * ⓘ [[ModuleDefinition]] অনুপস্থিত চাবিকে `[]` বানায়, তাই
             * সেখান থেকে *"ঘোষণা করা হয়েছে কি না"* জানা যায় না। ⛔ ঐ
             * পার্থক্যটাই এই দাবির গোটা বিষয়।
             */
            $raw = require app_path('Modules/'.self::folderOf($module->code).'/module.php');

            if (! array_key_exists('moves_money', $raw)) {
                $missing[] = $module->code;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই মডিউলগুলো অনুমোদন চায়, অথচ বলেনি কোন কাজে টাকা নড়ে:',
            '',
            '  '.implode(', ', $missing),
            '',
            '⛔ চাবিটা না থাকলে `?? []` খায়, আর ঐ মডিউলের প্রতিটা কাজ',
            '   একসাথে সইয়ে ঢুকে পড়ে — কোনো ত্রুটি ছাড়াই।',
            '',
            "ⓘ টাকা না নড়লেও লিখতে হবে: 'moves_money' => [] — কারণ",
            '   "কেউ ভেবে দেখেনি" আর "দেখে বলেছে নড়ে না" এক কথা নয়।',
        ]));
    }

    /**
     * ⓘ মডিউলের সংকেত থেকে ফোল্ডারের নাম।
     *
     * ⚠️ `system_admin` → `SystemAdmin` — তাই সোজা `ucfirst()` চলে না।
     */
    private static function folderOf(string $code): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $code)));
    }
}
