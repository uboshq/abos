<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use Tests\TestCase;

/**
 * module.php-তে লেখা চাবি পর্দা পর্যন্ত পৌঁছায় তো?
 *
 * ── ⚠️ কোন ভুল থেকে এই ফাইলটা এসেছে ──────────────────────────────────
 * [[SettingsService::definitions()]] আগে একটা **হাতে লেখা তালিকা** ধরে
 * মডিউলের সেটিং থেকে চাবি তুলত — `type`, `default`, `group`, `label`।
 * তালিকায় নেই এমন চাবি নীরবে হারাত: কোনো ব্যতিক্রম নয়, কোনো সতর্কতা
 * নয়, কেবল অনুপস্থিতি।
 *
 * ⓘ এটা **দুইবার** ঘটেছে, আর দ্বিতীয়বারটা প্রথমটার পাশে লেখা
 * সতর্কবার্তা সত্ত্বেও:
 *
 *   ১. `holds` — পাহারাটা লেখা ছিল, কন্ট্রোলার সেটা কখনো দেখতই না, আর
 *      কাগজভরা পর্দা দিব্যি বন্ধ হয়ে যাচ্ছিল। একটা লাইন যোগ করে সারানো
 *      হয়েছিল, আর পাশে মন্তব্যে লেখা হয়েছিল কেন বিপদটা বাস্তব।
 *
 *   ২. `tab` — [[ControlPanelController::crossTabs()]] ঘোষণা থেকে ট্যাব
 *      গোনে (`'tab' => 'counter'`), আর ওটাই §১৯.৭ মানার উপায়: কোরে
 *      মডিউলের নাম না লিখে সেটিং নিজেই বলে সে কোথায় বসতে চায়। ⛔ কিন্তু
 *      চাবিটা তালিকায় ছিল না, তাই `$definition['tab']` চিরকাল null —
 *      **ব্যবস্থাটা লেখা ছিল আর কোনোদিন চলতে পারত না**।
 *
 * ⚠️ দ্বিতীয়টা কেউ টের পায়নি, কারণ আজ পর্যন্ত কোনো মডিউল `tab` ঘোষণা
 * করেনি। প্রথম যিনি করতেন, তিনি একটা **নীরব অ-ঘটনা** পেতেন — ট্যাবটা
 * আসত না, ভুলও দেখাত না, আর কারণ খোঁজা শুরু হত ভুল ফাইলে।
 *
 * ⭐ ধরা পড়েছে ১২ সেপ্টেম্বর ২০২৬-এ larastan বসানোর দিনে: PHPStan
 * বলছিল `Offset 'tab' … does not exist` আর `in_array() … always false`।
 * সংকেতটা ঠিক ছিল, আর সেটা baseline-এ চাপা পড়তে বসেছিল।
 *
 * ── এই পরীক্ষাটা যা পাহারা দেয় ───────────────────────────────────────
 * সারানো হয়েছে নাম-ধরে-তোলা বন্ধ করে (`$setting` পুরোটাই যায়)। কিন্তু
 * একটা সারাই আর একটা নিয়ম এক জিনিস নয় — কেউ একদিন "পরিষ্কার" করতে গিয়ে
 * আবার তালিকা লিখতে পারেন। তখন এই পরীক্ষাটা ভাঙবে।
 *
 * ⓘ পরীক্ষাটা কোনো চাবির নাম জানে না, আর জানা উচিতও নয় — সে কেবল
 * মিলিয়ে দেখে **মডিউল যা বলল, কোর তার সবটা রাখল কি না**। তাই আগামী
 * দিনের অচেনা চাবিও আপনাআপনি পাহারায় থাকে।
 */
class AKeyDeclaredInModulePhpMustReachTheScreenTest extends TestCase
{
    /**
     * মডিউলের ঘোষণার প্রতিটা চাবি definitions()-এ আছে।
     */
    public function test_no_declared_key_is_dropped_on_the_way(): void
    {
        $registry = app(ModuleRegistry::class);
        $definitions = app(SettingsService::class)->definitions();

        $lost = [];

        foreach ($registry->all() as $module) {
            foreach ($module->settings as $setting) {
                $key = $setting['key'];

                $this->assertArrayHasKey(
                    $key,
                    $definitions,
                    "{$module->code} মডিউল '{$key}' সেটিংটি ঘোষণা করেছে, কিন্তু "
                    .'SettingsService::definitions() সেটি ফেরত দেয় না।'
                );

                foreach ($setting as $name => $value) {
                    /*
                     * `key` অ্যারের চাবি হয়ে আগেই আছে — মানের ভেতরে
                     * দ্বিতীয়বার রাখলে একদিন দুইটা আলাদা কথা বলত।
                     */
                    if ($name === 'key') {
                        continue;
                    }

                    if (! array_key_exists($name, $definitions[$key])) {
                        $lost[] = "{$module->code} · {$key} · '{$name}'";
                    }
                }
            }
        }

        $this->assertSame([], $lost,
            'module.php-তে ঘোষিত এই চাবিগুলো SettingsService::definitions()-এ পৌঁছায় না, '
            ."অর্থাৎ যে কোড ওগুলো পড়ে সে চিরকাল null পাবে — কোনো ভুল না দেখিয়ে:\n  "
            .implode("\n  ", $lost)
            ."\n\nকারণ প্রায় নিশ্চিতভাবেই definitions()-এ আবার হাতে লেখা চাবির তালিকা ফিরে এসেছে। "
            .'সেখানে নাম ধরে না তুলে পুরো ঘোষণাটা রাখতে হবে।');
    }

    /**
     * ঘোষণা যা বলেনি, কোর তার জন্য একটা ডিফল্ট রাখে।
     *
     * ⓘ উল্টো দিকটাও দরকার: চাবি হারানো যেমন নীরব, তেমনি **অনুপস্থিত
     * ডিফল্টও** নীরব। `$definition['label']` না থাকলে পর্দায় খালি জায়গা
     * আসত, আর কেউ বুঝত না কেন।
     */
    public function test_every_definition_can_answer_the_questions_a_screen_asks(): void
    {
        $definitions = app(SettingsService::class)->definitions();

        $this->assertNotEmpty($definitions,
            'একটাও সেটিং পাওয়া যায়নি — তার মানে রেজিস্ট্রি লোড হয়নি, '
            .'আর তাহলে এই পরীক্ষাটা কিছুই দেখেনি অথচ পাশ করত।');

        $incomplete = [];

        foreach ($definitions as $key => $definition) {
            foreach (['type', 'default', 'module', 'group', 'label'] as $needed) {
                if (! array_key_exists($needed, $definition)) {
                    $incomplete[] = "{$key} · '{$needed}'";
                }
            }
        }

        $this->assertSame([], $incomplete,
            "এই সেটিংগুলোর ঘরগুলো নেই, অথচ Control Panel-এর পর্দা ওগুলো ধরে নিয়ে আঁকে:\n  "
            .implode("\n  ", $incomplete));
    }
}
