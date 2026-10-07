<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * নিরীক্ষার খাতায় কাজের ঘরে চাবিটাই ছাপা হত।
 *
 * ── ⛔ কী দেখা গিয়েছিল, ২৯ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * লাইভের প্রতিটা মেনু পাতা হেঁটে দেখতে গিয়ে `/governance/audit` পাতায় কাজের
 * ঘরে `governance::action.repriced` — মালিক আর নিরীক্ষক, দুই ভূমিকাতেই।
 *
 * ── ⚠️ আর কারণটা অনুবাদের ভুল নয়, দরজাটার আকৃতি ──────────────────────
 * [[AuditTrail::ACTIONS]]-এ আটটা কাজ ঘোষিত, অথচ
 * [[IsAudited::auditAction()]] **স্বাধীন স্ট্রিং** নেয় — সেবাগুলো নিজের নাম
 * বসায় (`repriced`, `shift_closed`)। ⓘ চারটা নামের কোনো ভাষাতেই শব্দ ছিল না।
 *
 * ── ⭐ তাই শুধু শব্দ যোগ করা যথেষ্ট নয় ───────────────────────────────
 * আজ চারটা যোগ করলাম, কিন্তু কাল কেউ পাঁচ নম্বরটা লিখবে আর কোথাও লাল হবে
 * না। ⚠️ তাই এই দাবিটা **গোনে**: কোডে যে কাজগুলো সত্যিই লেখা হয়, তার
 * প্রতিটার দুই ভাষায় শব্দ আছে কি না — নমুনা নয়, তালিকা।
 *
 * ⛔ সংখ্যা নয়, নাম: গোনা সংখ্যা মিলে গেলেও একটা নাম বাদ পড়তে পারে, আর
 * "কতটা দেখলাম" কখনো "কোনটা বাদ গেল"-এর উত্তর নয়।
 *
 * ⚠️ শব্দগুলো ভাষার **ফাইল** থেকে পড়া, `__()` দিয়ে নয়: `__($k, [], 'bn')`
 * বাংলা না পেলে ইংরেজিতে ফিরে যায়, তাই অনুবাদক দিয়ে মাপলে বাংলা শব্দ
 * মুছে দিলেও দাবিটা সবুজ থাকত — একবার থেকেছেও।
 */
final class TheAuditTrailShowedItsOwnKeyTest extends TestCase
{
    /*
     * ⓘ বীজ বসে কেবল খোলা-পাতার দাবিটার ভিতরে। ⚠️ বাকি তিনটা দাবি
     * খাতা ছোঁয় না, আর ওগুলো দ্রুত থাকাটার দাম আছে — যে পাহারা চালাতে
     * দুই মিনিট লাগে, সেটা কেউ চালায় না।
     */
    use RefreshDatabase;

    public function test_the_audit_page_prints_the_word_not_the_key(): void
    {
        /*
         * ⛔ লাইভে যা দেখা গিয়েছিল, হুবহু সেটাই — একটা খোলা পাতা।
         *
         * ⚠️ উপরের তিনটা দাবি স্থির: ভাষার ফাইল, পতনরোধ, আর পর্দার লেখা।
         * ⓘ তিনটাই সবুজ থেকেও পাতাটা ভাঙতে পারে (একটা `@php` ব্লক, একটা
         * ভুল ভেরিয়েবল), আর তখন নিরীক্ষক ৫০০ দেখেন। ⭐ তাই পাতাটা
         * সত্যিই খুলে দেখা হয়।
         */
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /* ⓘ সেবার লেখা নামটাই, হাতে টাইপ করা নয় — [[BatchService]] এটাই বসায় */
        $action = 'repriced';

        $this->assertContains($action, $this->actionsTheCodeWrites(),
            'কোডে এই নামে কোনো কাজ আর লেখা হয় না, তাই দাবিটা মরা মান মাপছে।');

        $trail = AuditTrail::query()->create([
            'company_id' => $company->id,
            'branch_id' => $company->defaultBranch()?->id,
            'user_id' => $owner->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $owner->id,
            'document_no' => 'PRB-0001',
            'label' => 'Probe',
        ]);

        $this->actingAs($owner);

        $word = (string) __('governance::action.'.$action);

        foreach (['/governance/audit', '/governance/audit/'.$trail->id] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('governance::action.', $html,
                $url.' পাতায় কাজের ঘরে কাঁচা চাবি ছাপা হচ্ছে।');

            $this->assertStringContainsString($word, $html,
                $url.' পাতায় কাজের শব্দটাই নেই — দাবিটা তখন একটা খালি '
                .'পাতাও মেনে নিত।');
        }
    }

    public function test_every_action_the_code_writes_has_words_in_both_languages(): void
    {
        $actions = $this->actionsTheCodeWrites();

        $this->assertGreaterThan(8, count($actions),
            'ঘোষিত আটটার বাইরে কোনো কাজ পাওয়া গেল না — খোঁজাটাই ভেঙেছে, '
            .'আর তখন দাবিটা কিছুই মাপে না।');

        foreach (['bn', 'en'] as $locale) {
            $words = $this->words($locale);

            $absent = array_values(array_filter(
                $actions,
                fn (string $a) => ! array_key_exists($a, $words),
            ));

            $this->assertSame([], $absent,
                $locale.' ভাষায় এই কাজগুলোর শব্দ নেই, তাই নিরীক্ষার খাতায় '
                .'চাবিটাই ছাপা হবে: '.implode(', ', $absent));
        }
    }

    public function test_a_word_nobody_wrote_still_never_reads_as_a_key(): void
    {
        /*
         * ⓘ পতনরোধটা: আগামীকালের কাজের নামটা আজ এখানে নেই, আর তবুও
         * নিরীক্ষক যেন চাবি না পড়েন।
         *
         * ⚠️ নামটা এখানে **টাইপ করা**, আর সেটা এই একটা ক্ষেত্রেই নিরাপদ —
         * দাবিটা ঠিক "কোডে নেই এমন নাম" নিয়েই, তাই চেনা নাম দিলে ওটা
         * কোনোদিন ব্যর্থ হতে পারত না।
         */
        $never = 'a_thing_no_service_has_named_yet';

        $this->assertArrayNotHasKey($never, $this->words('bn'),
            'নামটা ভাষার ফাইলে ঢুকে গেছে, তাই এই দাবিটা আর পতনরোধ মাপে না।');

        $words = AuditTrail::actionInWords($never);

        $this->assertStringNotContainsString('::', $words,
            'অচেনা কাজের নাম এখনও চাবি হয়ে ফেরত আসছে।');

        $this->assertStringNotContainsString('_', $words,
            'নামটা যেমন ছিল তেমনই ফিরছে — মানুষের পড়ার মতো হয়নি।');

        $this->assertSame('—', AuditTrail::actionInWords(null));
        $this->assertSame('—', AuditTrail::actionInWords('   '));
    }

    public function test_no_audit_screen_builds_the_key_by_hand_any_more(): void
    {
        /*
         * ⛔ শব্দ আর পতনরোধ দুইটাই থাকতে পারে, আর পর্দা তবুও নিজে চাবি
         * বানাতে পারে — তখন পতনরোধটা ছোঁয়াই হয় না।
         *
         * ⓘ পাঁচটা জায়গায় হাতে বানানো হত: তালিকার ঘর, ছাঁকনির ড্রপডাউন,
         * এক কাগজের ইতিহাস, আর দেখার পাতার দুই জায়গা। ⚠️ পাঁচটা, কারণ
         * প্রথমে দুইটা ধরে বসে ছিলাম।
         */
        $screens = File::glob(base_path('app/Modules/Governance/Resources/views/audit/*.blade.php'));

        $this->assertNotEmpty($screens, 'নিরীক্ষার কোনো পর্দাই পাওয়া গেল না।');

        $guilty = [];

        foreach ($screens as $screen) {
            $body = (string) File::get($screen);

            if (str_contains($body, "governance::action.' .") || str_contains($body, "governance::action.'.")) {
                $guilty[] = basename($screen);
            }
        }

        $this->assertSame([], $guilty,
            'এই পর্দাগুলো এখনও নিজে চাবি জোড়া লাগায়, তাই '
            .'[[AuditTrail::actionInWords()]]-এর পতনরোধ ওখানে পৌঁছায় না: '
            .implode(', ', $guilty));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ⭐ কোডে সত্যিই যে কাজগুলো লেখা হয় — ঘোষিত আটটা, আর সেবাগুলোর নিজের নাম।
     *
     * ⚠️ `auditAction()` দুইভাবে ডাকা হয়: সরল স্ট্রিং দিয়ে, আর ক্লাসের
     * কনস্ট্যান্ট দিয়ে। ⓘ কনস্ট্যান্টটার মান একই ফাইলে খুঁজে নেওয়া হয়,
     * কারণ `self::SENT_BACK` নামটা নিজে কিছু বলে না।
     *
     * @return list<string>
     */
    private function actionsTheCodeWrites(): array
    {
        $found = AuditTrail::ACTIONS;

        foreach (File::allFiles(base_path('app')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $body = (string) File::get($file->getPathname());

            if (! str_contains($body, 'auditAction(')) {
                continue;
            }

            preg_match_all(
                "/auditAction\(\s*(?:'([a-z][a-z_]*)'|(?:self|static)::([A-Z][A-Z_]*))/",
                $body,
                $calls,
                PREG_SET_ORDER,
            );

            foreach ($calls as $call) {
                if (($call[1] ?? '') !== '') {
                    $found[] = $call[1];

                    continue;
                }

                $const = $call[2] ?? '';

                if ($const === '' || ! preg_match(
                    "/const\s+".preg_quote($const, '/')."\s*=\s*'([a-z][a-z_]*)'/",
                    $body,
                    $value,
                )) {
                    /* ⛔ চিনতে না পারলে চুপ করে ছেড়ে দেওয়া চলবে না */
                    $this->fail($file->getRelativePathname().'-এ `'.$const
                        .'` কনস্ট্যান্টটার মান পড়া গেল না, তাই ওই কাজটা গোনার '
                        .'বাইরে থেকে যেত।');
                }

                $found[] = $value[1];
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /** @return array<string, string> */
    private function words(string $locale): array
    {
        $path = base_path('app/Modules/Governance/Resources/lang/'.$locale.'/action.php');

        $this->assertFileExists($path);

        /** @var array<string, string> $words */
        $words = require $path;

        return $words;
    }
}
