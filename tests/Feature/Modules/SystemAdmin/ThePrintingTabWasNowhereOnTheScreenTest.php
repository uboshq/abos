<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Print\PrintFormat;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ছাপার ট্যাবটা পর্দার কোথাও ছিল না, আর নমুনা দেখার পথও ছিল না।
 *
 * ── ⭐ মালিকের দুইটা কথা, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * *"eikhane kotaw printing tab nai"* (কন্ট্রোল প্যানেলের ছবি সহ), আর
 * তার পরেই *"ami agei bolechi print seting alada seting hobe, ekhane
 * alada tab hobe"*।
 *
 * তার আগে: *"print e invoice template vew kore deke select korar bebosta
 * koro. zate age sample dekha zay tarpor select kora zay"*।
 *
 * ── ⛔ এই ফাইলের আসল পাহারা ──────────────────────────────────────────
 * *"ট্যাবটা আছে"* দাবিটা সহজ, আর একটা লিংক দেখেই সবুজ হয়ে যেত। ⚠️ যে
 * চারটা জিনিস নীরবে ভাঙে:
 *
 *   ১. নমুনাটা বাছা রূপটাই আঁকছে কি না — নাকি সবার জন্য একই কাগজ
 *   ২. নমুনায় কারও **আসল** বিল ঢুকে পড়ছে কি না
 *   ৩. একটা কাগজ সংরক্ষণ করলে বাকিগুলো অক্ষত থাকছে কি না
 *   ৪. দাম-ছাড়া রূপের নমুনাতেও দাম উঠছে কি না
 *
 * ⓘ (৩) সবচেয়ে ভয়ংকর আর সবচেয়ে নীরব: কাগজগুলো আলাদা ঠিকানায় যাওয়ার
 * পর একটা পাঠানোয় কেবল একটা কাগজের ঘর থাকে, আর নিয়মটা না বদলালে
 * চালানের সুইচ বদলে সংরক্ষণ করতেই বাকি পাঁচটা কাগজ "সাধারণ" রূপে ফিরে
 * যেত। ⚠️ পর্দায় কিছুই লাল হত না — মালিক মাস পরে কাগজ হাতে নিয়ে
 * বুঝতেন।
 */
final class ThePrintingTabWasNowhereOnTheScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    // ── ১ · ট্যাবটা সারিতে আছে ────────────────────────────────────────

    public function test_the_control_panel_row_carries_a_printing_tab(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('system_admin.control-panel'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('system_admin.print_control'),
            (string) $html,
            'কন্ট্রোল প্যানেলের ট্যাবের সারিতে ছাপার কোনো পথ নেই — '
            .'মালিক ঠিক এই অভিযোগটাই করেছেন ("eikhane kotaw printing tab nai")।',
        );
    }

    public function test_the_printing_screen_shows_the_same_row_with_its_own_tab_lit(): void
    {
        $html = (string) $this->actingAs($this->owner)
            ->get(route('system_admin.print_control'))
            ->assertOk()
            ->getContent();

        /* সারিটা এসেছে — পাশের ট্যাবগুলোর একটা ধরে দেখা */
        $this->assertStringContainsString(
            route('system_admin.control-panel', ['tab' => 'switches']),
            $html,
            'ছাপার পর্দায় কন্ট্রোল প্যানেলের সারিটা নেই, তাই ওখান থেকে '
            .'আর কোনো ট্যাবে ফেরা যায় না — ট্যাবটা তখন এক-মুখো দরজা।',
        );
    }

    // ── ২ · প্রতিটা কাগজের নিজের ঠিকানা ───────────────────────────────

    public function test_each_paper_has_its_own_address(): void
    {
        foreach (PrintProfile::TARGETS as $target) {
            $this->actingAs($this->owner)
                ->get(route('system_admin.print_control', ['paper' => $target]))
                ->assertOk()
                ->assertSee('papers['.$target.'][format]', escape: false);
        }
    }

    public function test_an_unknown_paper_falls_back_instead_of_breaking(): void
    {
        $this->actingAs($this->owner)
            ->get(route('system_admin.print_control', ['paper' => 'nonsense']))
            ->assertOk()
            ->assertSee('papers['.PrintProfile::TARGETS[0].'][format]', escape: false);
    }

    // ── ৩ · নমুনাটা সত্যিই বাছা রূপটা আঁকে ─────────────────────────────

    public function test_the_sample_draws_the_format_it_was_asked_for(): void
    {
        /*
         * ⓘ `no_price` দামের কলাম ফেলে দেয়, `wholesale` রাখে। ⛔ নমুনাটা
         * যদি সেটিংস পড়ত (বা রূপটা উপেক্ষা করত) তাহলে দুইটা এক আসত —
         * আর মালিক তেরোটা নাম দেখে একটাই কাগজ পেতেন।
         */
        $priced = $this->sample('invoice', 'wholesale');
        $bare = $this->sample('invoice', 'no_price');

        $this->assertNotSame($priced, $bare,
            'দুইটা আলাদা রূপ হুবহু একই নমুনা আঁকছে — তাহলে নমুনাটা রূপটা '
            .'দেখছেই না, আর "আগে দেখে তারপর বাছা" কথাটার কোনো মানে থাকে না।');

        $this->assertStringContainsString('120.00', $priced,
            'পাইকারি রূপের নমুনায় দরটাই নেই।');

        $this->assertStringNotContainsString('120.00', $bare,
            'দাম-ছাড়া রূপের নমুনায় দর উঠে এসেছে — অর্থাৎ নমুনাটা রূপের '
            .'কলামের তালিকা মানছে না।');
    }

    public function test_the_sample_does_not_read_the_saved_settings(): void
    {
        /*
         * ⛔ এটাই [[PrintProfile::previewing()]]-এর গোটা কারণ।
         *
         * ⚠️ সংরক্ষিত রূপ `no_price` বসিয়ে দিয়ে `wholesale`-এর নমুনা
         * চাওয়া হয়। ⓘ নমুনা যদি সেটিংস পড়ত, সে `no_price` আঁকত — আর
         * তখন **যা এখনো বাছা হয়নি** তা দেখার কোনো উপায়ই থাকত না।
         */
        app(SettingsService::class)->set('print.invoice.format', 'no_price');
        app(SettingsService::class)->set('print.invoice.parts', ['title']);

        $html = $this->sample('invoice', 'wholesale');

        $this->assertStringContainsString('120.00', $html,
            'সংরক্ষিত রূপটা নমুনার উপর বসে গেছে — তাহলে বাছার আগে কোনো '
            .'রূপই দেখা যেত না, আর পর্দাটা মিথ্যা বলত।');
    }

    // ── ৪ · নমুনায় কারও আসল কাগজ নেই ─────────────────────────────────

    public function test_the_sample_never_shows_a_real_customer(): void
    {
        /*
         * ⛔ এই পর্দার চাবি `settings.manage`, আর বিক্রয়ের কাগজ দেখার
         * চাবি আলাদা। ⚠️ ডেটাবেস থেকে শেষ বিলটা তুলে দেখালে এই রুটটা
         * একজন গ্রাহকের নাম ও তাঁর দর পড়ার **দ্বিতীয় দরজা** হত।
         *
         * ⓘ ডেমোর গ্রাহকদের নাম ধরে ধরে মিলিয়ে দেখা হয় — একটাও যেন
         * নমুনায় না থাকে।
         */
        $html = $this->sample('invoice', 'standard');

        $names = \App\Modules\Customer\Models\Customer::query()->get()
            ->map(fn ($customer) => (string) $customer->name())
            ->filter(fn (string $name) => $name !== '')
            ->all();

        $this->assertNotSame([], $names,
            'ডেমোয় একজন গ্রাহকও নেই, তাই এই পাহারাটা কিছুই মাপছে না — '
            .'সবুজ হলেও সেটা ফাঁকা সবুজ।');

        foreach ($names as $name) {
            $this->assertStringNotContainsString($name, $html,
                "নমুনার কাগজে একজন আসল গ্রাহকের নাম ({$name}) উঠে এসেছে।");
        }
    }

    // ── ৫ · একটা কাগজ সংরক্ষণ করলে বাকিরা অক্ষত ───────────────────────

    public function test_saving_one_paper_leaves_the_others_alone(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('print.invoice.format', 'wholesale');
        $settings->set('print.pos.format', 'compact');

        $this->actingAs($this->owner)
            ->from(route('system_admin.print_control', ['paper' => 'challan']))
            ->put(route('system_admin.print_control.update'), [
                'scope' => ['challan'],
                'papers' => ['challan' => ['format' => 'boxed']],
            ])
            ->assertRedirect();

        $this->assertSame('boxed', (string) $settings->get('print.challan.format'));

        $this->assertSame('wholesale', (string) $settings->get('print.invoice.format'),
            'চালান সংরক্ষণ করতেই বিলের রূপটা বদলে গেছে — অর্থাৎ ফর্মে '
            .'অনুপস্থিত কাগজগুলোও ছোঁয়া হচ্ছে, আর ছয়টা কাগজ নীরবে '
            .'"সাধারণ" রূপে ফিরে যেত।');

        $this->assertSame('compact', (string) $settings->get('print.pos.format'));
    }

    public function test_a_form_that_names_no_paper_changes_nothing(): void
    {
        /*
         * ⓘ পুরনো কোনো পাতা `scope[]` ছাড়া এলে কিছুই সংরক্ষণ হয় না।
         * ⚠️ "কিছু সেভ হলো না" ব্যবহারকারী সাথে সাথে দেখেন; "ছয়টা কাগজ
         * নীরবে রিসেট" কেউ মাসের পর মাস দেখেন না।
         */
        $settings = app(SettingsService::class);
        $settings->set('print.invoice.format', 'striped');

        $this->actingAs($this->owner)
            ->from(route('system_admin.print_control'))
            ->put(route('system_admin.print_control.update'), [
                'papers' => ['invoice' => ['format' => 'boxed']],
            ])
            ->assertRedirect();

        $this->assertSame('striped', (string) $settings->get('print.invoice.format'),
            'কোন কাগজ বলা হয়নি, তবু একটা বদলে গেছে।');
    }

    // ── ৬ · দরজাটা বন্ধ ───────────────────────────────────────────────

    public function test_the_sample_is_closed_to_anyone_without_the_key(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($salesman)
            ->get(route('system_admin.print_control.preview', [
                'paper' => 'invoice',
                'format' => 'standard',
            ]))
            ->assertForbidden();
    }

    // ── ৭ · প্রতিটা রূপের নমুনা সত্যিই আঁকা যায় ────────────────────────

    public function test_every_format_and_every_paper_draws_a_sample(): void
    {
        /*
         * ⚠️ পর্দায় তেরোটা রূপের তেরোটা iframe বসে। ⛔ তার একটা ৫০০
         * দিলে মালিক একটা ভাঙা বাক্স দেখতেন আর ধরে নিতেন রূপটাই নষ্ট।
         */
        foreach (PrintProfile::TARGETS as $target) {
            foreach (PrintFormat::all() as $format) {
                $this->actingAs($this->owner)
                    ->get(route('system_admin.print_control.preview', [
                        'paper' => $target,
                        'format' => $format,
                    ]))
                    ->assertOk();
            }
        }
    }

    private function sample(string $paper, string $format): string
    {
        return (string) $this->actingAs($this->owner)
            ->get(route('system_admin.print_control.preview', [
                'paper' => $paper,
                'format' => $format,
            ]))
            ->assertOk()
            ->getContent();
    }
}
