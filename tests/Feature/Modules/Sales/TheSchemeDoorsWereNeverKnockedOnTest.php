<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\CommissionRule;
use App\Modules\Sales\Models\Scheme;
use App\Modules\Sales\Services\SchemeService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * স্কিমের সাতটা দরজার একটাতেও কেউ কোনোদিন কড়া নাড়েনি।
 *
 * ── ⛔ কেন এই ফাইলটা, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * বিক্রয়ের চেকলিস্টে ১০৪টা রুট একে একে খুঁজে দেখা গেছে **৩৭টা দরজা
 * কোনো পরীক্ষা নাম ধরে ডাকে না** (`de052cdc`)। ⚠️ স্কিম সবচেয়ে খারাপ
 * অবস্থায়: **৯টার ৭টা**।
 *
 * ⓘ কাজটা ভালোভাবেই মাপা — `TheRateWasInSomebodysHeadTest`-এ ১৮টা দাবি।
 * ⛔ কিন্তু সবগুলোই সেবা ধরে: `app(SchemeService::class)->activate($scheme)`।
 * দরজার দিকে কেবল `index` আর `show`।
 *
 * ⚠️ তাই যা ধরা পড়ত না: `sales.scheme.manage` চাবিটা যদি কোনোদিন
 * `middleware()` থেকে খসে যেত, তবে **যে কেউ স্কিম বানাতে, চালু করতে আর
 * কমিশনের হার বদলাতে পারতেন** — আর ১৮টা দাবির একটাও লাল হত না, কারণ
 * তারা কেউ দরজা দিয়ে ঢোকে না।
 *
 * ⓘ পরিবেশনের ব্যবসায় স্কিমই কমিশনের হার ঠিক করে, তাই এটা টাকার দরজা।
 *
 * ── ⭐ নকশা: একই লোক, আগে চাবি ছাড়া, পরে চাবি নিয়ে ───────────────────
 * ⚠️ দুইজন আলাদা ব্যবহারকারী নিলে ৪০৩ আসতে পারত অন্য কারণেও — সদস্যপদ,
 * পর্দার সুইচ, ভুল রুট-নাম। ⭐ একই লোককে চাবি দিলে দরজা খুলে যাওয়াটাই
 * প্রমাণ করে তালাটা ঠিক ঐ চাবির ([[same-user-key-off-then-on]])।
 */
final class TheSchemeDoorsWereNeverKnockedOnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        /*
         * ⭐ চাবিহীন লোকটা **ভূমিকাহীন**, কোনো ডেমো-ভূমিকা নয়।
         *
         * ── ⛔ কেন, ২৬ সেপ্টেম্বর ২০২৬ ────────────────────────────
         * ⚠️ প্রথমে বিক্রয়কর্মী ধরেছিলাম — ভুল, তাঁর চাবি ছিল। তারপর
         * হিসাবরক্ষক — ⛔ সেটাও ভুল হয়ে যাবে, কারণ মালিকের সিদ্ধান্তে
         * `sales.claim.decide` এখন **হিসাবরক্ষকের ভূমিকাতেই** যাচ্ছে।
         *
         * ⓘ অর্থাৎ যেকোনো ডেমো-ভূমিকার উপর দাঁড়ালে এই দাবিগুলো
         * ভূমিকার ছাঁচ বদলানোমাত্র **ভুল কারণে** লাল হত।
         *
         * ⭐ তাই কারো ভূমিকা ধার করা হয় না: একজন নতুন ব্যবহারকারী,
         * কোম্পানির সদস্য কিন্তু ভূমিকাহীন, আর চাবিটা প্রতিটা দাবির
         * ভিতরেই দেওয়া হয় ([[same-user-key-off-then-on]])।
         */
        $this->outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->outsider->companies()->attach($this->company->id, ['is_active' => true]);
    }

    /**
     * ⛔ চাবি ছাড়া স্কিম বানানো যায় না — আর সারিটাও বসে না।
     *
     * ⓘ গণনা নয়, **কোড ধরে** দেখা: বীজে স্কিম থাকলেও দাবিটা সত্যি থাকে
     * ([[never-supply-the-name-yourself]]-এর উল্টো দিক — নামটা আমি
     * বসাচ্ছি, তাই সেটাই খোঁজা নিরাপদ)।
     */
    public function test_a_scheme_cannot_be_created_without_the_key(): void
    {
        $this->assertFalse($this->outsider->can('sales.scheme.manage'),
            'ⓘ এই দাবির ভিত্তি: ভূমিকাহীন ব্যবহারকারীর স্কিম পরিচালনার চাবি নেই।');

        $this->actingAs($this->outsider)
            ->post(route('sales.scheme.store'), $this->newScheme())
            ->assertForbidden();

        $this->assertFalse(Scheme::query()->where('code', 'SCHEMEDOOR')->exists(),
            '⛔ ৪০৩ ফিরেছে, তবু স্কিমটা তৈরি হয়ে গেছে।');
    }

    /** ⭐ চাবিটাই দরজা খোলে — আর খুলে সত্যিই স্কিমটা বসায়। */
    public function test_the_key_is_what_opens_the_scheme_door(): void
    {
        $this->outsider->givePermissionTo('sales.scheme.manage');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.scheme.store'), $this->newScheme())
            ->assertRedirect();

        $this->assertTrue(Scheme::query()->where('code', 'SCHEMEDOOR')->exists(),
            '⛔ দরজা খুলেছে, কিন্তু স্কিমটা বসেনি — ৩০২ এসেছে, কাজ হয়নি।');
    }

    /**
     * ⛔ চাবি ছাড়া স্কিম চালু করা যায় না — অবস্থাও বদলায় না।
     *
     * ⚠️ এটাই সবচেয়ে ভারী দরজা: চালু স্কিম প্রতিটা বিলে কমিশন হিসাব করে।
     */
    public function test_a_scheme_cannot_be_activated_without_the_key(): void
    {
        $scheme = $this->draftScheme();

        $this->actingAs($this->outsider)
            ->post(route('sales.scheme.activate', $scheme))
            ->assertForbidden();

        $this->assertNotSame(Scheme::ACTIVE, $scheme->fresh()->status,
            '⛔ ৪০৩ ফিরেছে, তবু স্কিমটা চালু হয়ে গেছে — কমিশন গুনতে শুরু করত।');
    }

    /**
     * ⭐ চাবি নিয়ে চালু করা — অবস্থা সত্যিই বদলায়।
     *
     * ⚠️ **নিয়ম ছাড়া স্কিম চালু হয় না** — `SchemeService::activate()`
     * `scheme_has_no_rule` দিয়ে আটকায় (ঐ ফাইলের ১০৪ লাইন)। ⓘ প্রথমে
     * নিয়ম না বসিয়েই চালু করতে গিয়েছিলাম, আর দাবিটা লাল হয়েছিল —
     * ⛔ কোডের দোষে নয়, আমার পরীক্ষার দোষে।
     *
     * ⓘ সিঁড়ির উপরের ধাপটা খোলা রাখা হয় (`slab_to` নেই), কারণ সব ধাপ
     * বন্ধ থাকলে বছরের সবচেয়ে বড় বিলটা ছকের উপর দিয়ে বেরিয়ে শূন্য পায়।
     */
    public function test_the_key_activates_the_scheme(): void
    {
        $scheme = $this->draftScheme();

        app(SchemeService::class)->addRule($scheme, $this->newRule());

        $this->outsider->givePermissionTo('sales.scheme.manage');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.scheme.activate', $scheme))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(Scheme::ACTIVE, $scheme->fresh()->status,
            '⛔ দরজা খুলেছে, কিন্তু স্কিমটা চালু হয়নি।');
    }

    /**
     * ⛔ চাবি ছাড়া কমিশনের ধাপ বসানো যায় না।
     *
     * ⓘ ধাপটাই হার বলে দেয় — কে কত পাবেন। ⚠️ এখানে একটা সারি গুঁজে
     * দিতে পারলে টাকার অঙ্ক বদলে যেত।
     */
    public function test_a_commission_rule_cannot_be_added_without_the_key(): void
    {
        $scheme = $this->draftScheme();

        $this->actingAs($this->outsider)
            ->post(route('sales.scheme.rule.add', $scheme), $this->newRule())
            ->assertForbidden();

        $this->assertSame(0, CommissionRule::query()->where('scheme_id', $scheme->id)->count(),
            '⛔ ৪০৩ ফিরেছে, তবু কমিশনের একটা ধাপ বসে গেছে।');
    }

    /** ⭐ চাবি নিয়ে ধাপ বসানো — সারিটা সত্যিই আসে। */
    public function test_the_key_adds_the_commission_rule(): void
    {
        $scheme = $this->draftScheme();

        $this->outsider->givePermissionTo('sales.scheme.manage');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.scheme.rule.add', $scheme), $this->newRule())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, CommissionRule::query()->where('scheme_id', $scheme->id)->count(),
            '⛔ দরজা খুলেছে, কিন্তু ধাপটা বসেনি।');
    }

    /**
     * ⓘ কমিশনের দাবি বসানোর পর্দাটাও তার নিজের চাবি চায়।
     *
     * ⚠️ পড়ার পর্দা, তবু এটাই একমাত্র জায়গা যেখান থেকে দাবি তোলা যায়।
     */
    public function test_the_commission_create_screen_needs_its_key(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('sales.commission.create'))
            ->assertForbidden();

        $this->outsider->givePermissionTo('sales.commission.manage');

        $this->actingAs($this->outsider->fresh())
            ->get(route('sales.commission.create'))
            ->assertOk();
    }

    /**
     * একটা খসড়া স্কিম — সেবা ধরে, কারণ দরজাটা এখানে মাপার জিনিস নয়।
     */
    private function draftScheme(): Scheme
    {
        return app(SchemeService::class)->create($this->newScheme());
    }

    /** @return array<string, mixed> */
    private function newScheme(): array
    {
        return [
            'code' => 'SCHEMEDOOR',
            'name' => 'দরজার স্কিম',
            'basis' => Scheme::VALUE,
            'applies_to' => Scheme::ALL,
            'valid_from' => '2026-09-01',
            'valid_to' => '2026-12-31',
        ];
    }

    /**
     * কমিশনের একটা ধাপ।
     *
     * ⚠️ `level_order` **বাধ্যতামূলক** (`SchemeController::addRule`, ১-২০)।
     * ⛔ প্রথমে ওটা পাঠাইনি, আর ধাপটা বসেনি — দরজা ৩০২ দিয়েছিল, তাই
     * দেখতে সফলতার মতোই লাগত। ⓘ এখন দুইটা দাবিতেই
     * `assertSessionHasNoErrors()` আছে, তাই এই ফাঁদটা আর নীরব নয়।
     *
     * ⓘ `slab_to` ইচ্ছাকৃতভাবে নেই — সিঁড়ির উপরের ধাপ খোলা রাখতে হয়।
     *
     * @return array<string, mixed>
     */
    private function newRule(): array
    {
        return [
            'earner_role' => 'dealer',
            'rate_percent' => '2.5',
            'slab_from' => '0',
            'level_order' => '1',
        ];
    }
}
