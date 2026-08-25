<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Services\CustomFieldService;
use App\Modules\MasterData\Models\ReasonCode;
use Tests\TestCase;

/**
 * কোড জুড়ে বানানো চাবির কোনো লেখা ছিল না।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ২৫ আগস্ট ২০২৬-এ লাইভের প্রতিটা পর্দা ঘুরে দেখতে গিয়ে তিনটা পাতায়
 * অনূদিত লেখার বদলে **চাবিটাই** ছাপা পাওয়া গেল:
 *
 *   · `governance::action.look_published` — নিরীক্ষার খাতায়
 *   · `master_data::context.hold` — কারণ-কোডের পর্দায়
 *   · `core.source.product` — নিজস্ব ঘরের পর্দায়
 *
 * ছয়টা চাবি, আর ছয়টাই **দুই ভাষাতেই** অনুপস্থিত।
 *
 * ── কেন দুইটা পাহারা এগুলো ধরেনি ──────────────────────────────────────
 * `BothLanguagesSayTheSameThingTest` বাংলা ও ইংরেজি মেলায়। দুই দিকেই
 * চাবিটা নেই, তাই তুলনাটা নিখুঁত মেলে — ঠিক আজ সকালের ডুপ্লিকেট-চাবির
 * মতোই। **যে ফাঁক দুই পাশেই সমান, তুলনা সেটা দেখতে পায় না।**
 *
 * আর কোনো স্ট্যাটিক খোঁজাও এগুলো পেত না, কারণ চাবিগুলো উৎসে **লেখা
 * নেই**। ওরা তৈরি হয় চলার সময়:
 *
 *     __('governance::action.'.$action)
 *     __('master_data::context.'.$context)
 *     __('core.source.'.$entity)
 *
 * উৎসে দেখা যায় কেবল উপসর্গ। বাকিটা আসে একটা কোড থেকে — আর ওই কোডটা
 * যোগ করার সময় কেউ লেখাটা যোগ করতে ভুলে যান।
 *
 * ── তাই প্রশ্নটা উল্টো করে করা হয় ────────────────────────────────────
 * "উৎসে কোন চাবিগুলো লেখা আছে" নয়, বরং **"কোডগুলোর তালিকাটা কোথায়,
 * আর তার প্রতিটার লেখা আছে তো?"**
 *
 * তালিকাগুলো অনুমান করা হয় না — প্রতিটা তার নিজের উৎস থেকে আসে:
 * নিরীক্ষার কাজগুলো সোর্স কোড থেকে, কারণ-কোডের প্রসঙ্গ মডেলের ধ্রুবক
 * থেকে, আর নিজস্ব ঘরের জিনিসগুলো মডিউল রেজিস্ট্রি থেকে। ফলে নতুন
 * একটা কোড যোগ হলে সে **নিজে থেকেই** এই পরীক্ষার আওতায় আসে।
 */
class AKeyBuiltFromACodeHadNoWordsTest extends TestCase
{
    /**
     * নিরীক্ষার খাতায় লেখা প্রতিটা কাজের একটা নাম আছে।
     *
     * ── কেন তালিকাটা সোর্স কোড পড়ে বানানো ─────────────────────────────
     * কাজের নামগুলো কোনো enum-এ নেই — `recordAction($x, 'portal_enabled')`
     * বলে যেখানে দরকার সেখানেই লেখা হয়। তাই একমাত্র সত্যিকারের তালিকা
     * হলো সোর্স কোড নিজেই।
     *
     * হাতে একটা তালিকা রাখলে ঠিক এই ভুলটাই আবার হত: কেউ নতুন একটা
     * কাজ লিখতেন, আর তালিকায় যোগ করতে ভুলতেন।
     */
    public function test_every_audit_action_has_a_name(): void
    {
        $missing = [];

        foreach ($this->recordedActions() as $action => $files) {
            if (__('governance::action.'.$action) === 'governance::action.'.$action) {
                $missing[] = $action.' — '.implode(', ', array_unique($files));
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'নিরীক্ষার এই কাজগুলোর কোনো লেখা নেই — খাতায় চাবিটাই ছাপা হবে:',
            ...$missing,
            '',
            'lang/bn ও lang/en দুইটাতেই governance::action-এ যোগ করুন।',
        ]));
    }

    /** কারণ-কোডের প্রতিটা প্রসঙ্গের একটা নাম আছে। */
    public function test_every_reason_code_context_has_a_name(): void
    {
        $missing = [];

        foreach (ReasonCode::CONTEXTS as $context) {
            if (__('master_data::context.'.$context) === 'master_data::context.'.$context) {
                $missing[] = $context;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'কারণ-কোডের এই প্রসঙ্গগুলোর কোনো লেখা নেই:',
            ...$missing,
        ]));
    }

    /** নিজস্ব ঘর যে জিনিসগুলোয় বসে, প্রতিটার একটা নাম আছে। */
    public function test_every_thing_that_takes_custom_fields_has_a_name(): void
    {
        $missing = [];

        foreach (array_keys(app(CustomFieldService::class)->entities()) as $entity) {
            if (__('core.source.'.$entity) === 'core.source.'.$entity) {
                $missing[] = $entity;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'নিজস্ব ঘরের এই জিনিসগুলোর কোনো লেখা নেই:',
            ...$missing,
        ]));
    }

    /**
     * তিনটা তালিকাই সত্যিই ভরা।
     *
     * একটা তালিকা খালি ফিরলে উপরের পরীক্ষাটা চিরকাল সবুজ থাকত, আর
     * পাহারাটা নামেমাত্র হত। এই প্রকল্পে ঠিক ওরকম পাহারা আগে একাধিকবার
     * ধরা পড়েছে, তাই প্রতিটা খোঁজার সাথে একটা করে এমন পরীক্ষা থাকে।
     */
    public function test_the_lists_are_not_empty(): void
    {
        $this->assertGreaterThan(5, count($this->recordedActions()));
        $this->assertGreaterThan(3, count(ReasonCode::CONTEXTS));
        $this->assertGreaterThan(1, count(app(CustomFieldService::class)->entities()));
    }

    /**
     * সোর্স কোডে `recordAction()`-এ লেখা প্রতিটা কাজের নাম।
     *
     * @return array<string, list<string>>
     */
    private function recordedActions(): array
    {
        $found = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $short = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            /*
             * দ্বিতীয় যুক্তিটা যখন সরাসরি লেখা একটা স্ট্রিং।
             *
             * ভেরিয়েবল বা শর্তসাপেক্ষ মান এখানে ধরা পড়ে না — যেমন
             * `$was ? 'a' : 'b'`। ওগুলোও দরকার, কিন্তু ওদের ধরতে গেলে
             * PHP পার্স করতে হত, আর তাতে পাহারাটা এমন জটিল হত যে
             * ভাঙলে কেউ ঠিক করতে পারত না।
             *
             * সরল রূপটাই সংখ্যাগরিষ্ঠ, আর ছয়টা হারানো চাবির ছয়টাই
             * সরল রূপে লেখা ছিল।
             */
            preg_match_all(
                "/recordAction\(\s*[^,]+,\s*'([a-z_]+)'/",
                (string) file_get_contents($file->getPathname()),
                $hits,
            );

            foreach ($hits[1] as $action) {
                $found[$action][] = $short;
            }
        }

        return $found;
    }
}
