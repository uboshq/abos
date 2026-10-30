<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ফোনে মডিউল আর মেনু — মালিক, ৩০ অক্টোবর ২০২৬: *"mobile view e module icon nai tai module select korte zamela hobe,
 * sathe menur jonno bebosta koro"* (Material 3 navigation drawer; SAP Fiori-র ফোনের ☰)।
 *
 * ⛔ আগে: ৭৬৮-এর নিচে বাঁ রেল নেই, ☰-ও নেই ("কাজহীন বোতাম রাখা হয় না") — মডিউল বদলানো যেত কেবল নিচের চারটা আর "আরও" দিয়ে,
 * আর চলতি মডিউলের মেনু ছিল চিপের তিন সারি (৩৯০-এ পাতার মাথার অর্ধেক)।
 *
 * দাবি:
 *   ক — ফোনের ☰ পাতায় আছে, কেবল ৭৬৮-এর নিচে (`md:hidden`), আর সেটা ড্রয়ার খোলে (`$store.sidebar.phone`);
 *   খ — ড্রয়ারটা বড় পর্দার সাইডবারই: ফোনে খুললে পাতার উপরে বাঁ থেকে (`max-md:fixed! … max-md:flex!`), সব মডিউলের টাইল আর
 *       চলতি মডিউলের মেনু সেখানে, মেনুর প্যানেলও ফোনে দেখায় (বড় পর্দায় গুটানো থাকলেও);
 *   গ — বন্ধের পথ: পেছনের পর্দা চাপলে আর Esc-এ;
 *   ঘ — চিপের সারি ফোনে নেই (`max-md:hidden!`) — একই মেনু ড্রয়ারে, দুইবার নয়।
 */
final class APhoneReachesEveryModuleFromItsMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_menu_opens_the_sidebar_with_every_module_and_the_module_menu(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['ui' => 'navy'])->save();
        $html = (string) $this->actingAs($owner->fresh())->get(route('module.dashboard', 'accounts'))->assertOk()->getContent();

        // ক
        $this->assertMatchesRegularExpression('/<button[^>]*data-phone-menu-toggle[^>]*@click="\$store\.sidebar\.phone = true"[^>]*class="[^"]*\bmd:hidden\b/s', $html,
            '⛔ ফোনের মেনু: ☰ নেই, বা ড্রয়ার খোলে না, বা বড় পর্দাতেও দেখায়।');

        // খ
        $aside = substr($html, (int) strpos($html, '<aside x-data="sidebarFilter"'));
        $this->assertStringContainsString("\$store.sidebar.phone ? ' max-md:fixed! max-md:start-0 max-md:z-50! max-md:flex!", $aside,
            '⛔ ফোনের মেনু: ☰ চাপলেও সাইডবার ফোনে বেরোয় না।');
        $this->assertGreaterThan(5, substr_count($aside, 'data-rail-tile'), '⛔ ফোনের মেনু: ড্রয়ারে মডিউলের টাইল নেই।');
        $this->assertMatchesRegularExpression('/data-site-map[^>]*data-active-module="accounts"/s', $aside, '⛔ ফোনের মেনু: ড্রয়ারে চলতি মডিউলের মেনু নেই।');
        $this->assertStringContainsString("x-show=\"! \$store.sidebar.collapsed || \$store.sidebar.phone\"", $aside,
            '⛔ ফোনের মেনু: বড় পর্দায় মেনু গুটানো থাকলে ফোনের ড্রয়ারে শুধু আইকন — পাতার নাম পড়া যেত না।');
        $this->assertStringContainsString("+ (\$store.sidebar.phone ? ' max-md:flex!' : '')", $aside, '⛔ ফোনের মেনু: মেনুর প্যানেল ফোনে লুকানোই থাকে।');

        // গ
        $this->assertMatchesRegularExpression('/<div[^>]*x-show="\$store\.sidebar\.phone"[^>]*data-phone-menu-scrim[^>]*@click="\$store\.sidebar\.phone = false"/s', $html,
            '⛔ ফোনের মেনু: পেছনের পর্দা চেপে বন্ধ করা যায় না।');
        $this->assertStringContainsString('@keydown.escape.window="$store.sidebar.phone = false"', $aside, '⛔ ফোনের মেনু: Esc-এ বন্ধ হয় না।');

        // ঘ
        $this->assertMatchesRegularExpression('/<div data-module-bar[^>]*class="[^"]*max-md:hidden!/s', $html,
            '⛔ ফোনের মেনু: চিপের তিন সারি ফোনে ফিরে এসেছে — একই মেনু দুইবার, পাতার মাথা খেয়ে।');
    }
}
