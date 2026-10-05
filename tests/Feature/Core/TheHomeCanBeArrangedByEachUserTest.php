<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\HomeLayout;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "লেআউট সাজান" — মালিক, ৪ অক্টোবর ২০২৬: হোমের ভাগ লুকানো আর ক্রম বদলানো, প্রত্যেকের নিজের ([[HomeLayout]])।
 *
 * ⓘ দাবি: একই ব্যবহারকারী লুকালে অংশ চলে যায়, আবার দেখালে ফেরে; ক্রম পাতার `order`-এ বসে; অন্যের পাতা বদলায় না;
 * ভাঙা ইনপুট পাতা ভাঙে না; "আগের মতো" সব ফেরায়।
 */
final class TheHomeCanBeArrangedByEachUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_user_hides_and_reorders_and_another_user_keeps_the_default(): void
    {
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $other = User::query()->whereKeyNot($owner->id)->where('is_active', true)->firstOrFail();

        $page = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-layout-menu', $page, 'লেআউট সাজানোর ঘর নেই।');
        // ⓘ নতুন হোম (পরিকল্পনা ২, ৫ অক্টোবর ২০২৬): চার্ট আগে, তারপর মূল সূচক, তারপর কাজ
        $this->assertStringContainsString('data-home-unit="pictures" style="order: 1"', $page);
        $this->assertStringContainsString('data-home-unit="work" style="order: 3"', $page);
        $this->assertStringContainsString('data-business-pictures', $page, 'ছবি আগে থেকেই নেই — লুকানোর দাবি কিছু দেখবে না।');

        // ⭐ ছবি লুকানো, "কাজ" সবার উপরে
        $this->actingAs($owner)->post(route('home.layout'), [
            'position' => ['kpis' => 2, 'pictures' => 3, 'work' => 1],
            'show' => ['kpis' => 1, 'exceptions' => 1, 'happenings' => 1],
        ])->assertRedirect();

        $saved = HomeLayout::for($owner->fresh());
        $this->assertSame(['work', 'kpis', 'pictures'], $saved->order);
        $this->assertSame(['pictures'], $saved->hidden);

        $page = $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-home-unit="work" style="order: 1"', $page, '⛔ ক্রম পাতায় বসেনি।');
        $this->assertStringContainsString('data-home-unit="pictures" style="order: 3"', $page);
        $this->assertStringNotContainsString('data-business-pictures', $page, '⛔ লুকানো ছবি তবু আঁকা হয়েছে।');
        $this->assertStringContainsString('data-kpis', $page, '⛔ যা লুকানো হয়নি সেটাও চলে গেছে।');

        // ⓘ অন্য ব্যবহারকারীর পাতা আগের মতো
        $this->assertNull($other->fresh()->home_layout, '⛔ একজনের সাজ আরেকজনের ঘরে গেছে।');

        // ⓘ ভাঙা ইনপুট — অচেনা নাম ফেলে দেওয়া, সব ভাগ থাকে
        // ⓘ পুরনো নাম (period, overall) আর অচেনা নাম ফেলে দেওয়া — পুরনো সাজ নতুন পাতা ভাঙে না
        $this->assertSame(HomeLayout::UNITS, HomeLayout::from(['nope', 'period', 'overall', 'pictures', 'pictures'], ['x', 'overall'])->order);
        $this->assertSame([], HomeLayout::from([], ['x', 'y'])->hidden);
        $this->actingAs($owner->fresh())->post(route('home.layout'), ['position' => ['kpis' => 'abc']])->assertSessionHasErrors('position.kpis');

        // ⭐ "আগের মতো"
        $this->actingAs($owner->fresh())->post(route('home.layout'), ['reset' => '1'])->assertRedirect(route('dashboard'));
        $this->assertNull($owner->fresh()->home_layout);
        $page = $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-home-unit="pictures" style="order: 1"', $page);
    }
}
