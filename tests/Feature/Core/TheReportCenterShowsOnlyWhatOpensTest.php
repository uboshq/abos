<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * রিপোর্ট সেন্টার — মডিউল ধরে ভাগ, কেবল তৈরি রিপোর্ট, কেবল যা এই মানুষ খুলতে পারেন (রিপোর্ট সেন্টার ধাপ ১)।
 *
 * ⭐ মালিকের নীতি, ১ অক্টোবর ২০২৬: *"শীঘ্রই আসছে" জাতীয় খালি সারি নয় — যা তৈরি, কেবল সেটাই দেখায়।* ⓘ "বাকি" লাইন
 * (মালিকের তালিকা, [[ReportCenterPlan]]) কেবল মালিক/অ্যাডমিন দেখেন।
 *
 * ⓘ প্রতিটা দরজার দাবি একই মানুষ দুইবার — চাবি ছাড়া, তারপর চাবিসহ।
 */
final class TheReportCenterShowsOnlyWhatOpensTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⭐ মালিকের চোখে প্রতিটা "তৈরি" লিংক সত্যিই খোলে — মৃত লিংক নেই, আর মডিউলগুলো ভাগে ভাগে। */
    public function test_every_built_link_on_the_center_opens(): void
    {
        $body = (string) $this->get(route('reports.center'))->assertOk()->getContent();

        preg_match_all('/<a href="([^"]+)" data-map-done/', $body, $m);
        $links = array_unique(array_map('html_entity_decode', $m[1]));

        $this->assertGreaterThan(30, count($links), '⛔ রিপোর্ট সেন্টারে রিপোর্টই নেই — পাওয়া গেছে '.count($links).'টা।');

        foreach (['sales', 'purchase', 'inventory', 'accounts', 'customer', 'supplier'] as $code) {
            $this->assertStringContainsString('data-map-section="'.$code.'"', $body, "⛔ '{$code}' মডিউলের ভাগই নেই।");
        }

        $broken = [];

        foreach ($links as $url) {
            $response = $this->get($url);
            $status = $response->getStatusCode();
            $to = (string) $response->headers->get('Location');

            // ⓘ ৩০২ নিজে ভুল নয় (ছাঁকনি নিয়ে নিজের দিকে ফেরা) — ⛔ কিন্তু লগইন বা লাইসেন্সের তালায় গেলে বন্ধ দরজা
            if ($status === 302 && (str_starts_with($to, route('login')) || str_starts_with($to, route('licence.show')))) {
                $broken[] = "{$url} = 302 → {$to}";
            } elseif (! in_array($status, [200, 302], true)) {
                $broken[] = "{$url} = {$status}";
            }
        }

        $this->assertSame([], $broken, "⛔ রিপোর্ট সেন্টারে \"তৈরি\" লেখা, অথচ খোলে না:\n".implode("\n", $broken));
    }

    /** ⛔ একই মানুষ: রিপোর্টের চাবি নেই — তালিকায় নেই; চাবি দিলে আসে। আর "বাকি" লাইন তাঁর কাছে কখনো নয়। */
    public function test_a_report_without_the_key_is_not_on_the_center(): void
    {
        $clerk = $this->member();
        $url = e(route('sales.report.show', 'by-customer'));

        $page = $this->actingAs($clerk->fresh())->get(route('reports.center'))->assertOk();
        $page->assertDontSee($url, false);

        $clerk->givePermissionTo('sales.report');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $page = $this->actingAs($clerk->fresh())->get(route('reports.center'))->assertOk();
        $page->assertSee($url, false);
        $page->assertDontSee('data-map-pending', false);
        $page->assertDontSee('data-map-tally', false);
        $page->assertDontSee(e(route('accounts.report.show', 'ledger')), false);
    }

    /** ⛔ একই মানুষ: অ্যাডমিন নন — "বাকি" দেখেন না; সুপার অ্যাডমিন হলে দেখেন, অগ্রগতিসহ। */
    public function test_pending_reports_show_only_to_the_super_admin(): void
    {
        $clerk = $this->member();
        $clerk->givePermissionTo('sales.report');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk->fresh())->get(route('reports.center'))->assertOk()
            ->assertDontSee('data-map-pending', false)
            ->assertDontSee(__('report_center.plan.sales_analysis'));

        CompanyContext::forCompany($this->company->id, fn () => $clerk->fresh()->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk->fresh())->get(route('reports.center'))->assertOk()
            ->assertSee('data-map-pending', false)
            ->assertSee('data-map-tally', false)
            ->assertSee(__('report_center.plan.sales_analysis'));
    }

    /** ⛔ কোম্পানিতে মডিউল বন্ধ — তার রিপোর্ট রিপোর্ট সেন্টারেও নেই (সাইডবারের একই উত্তর)। */
    public function test_a_switched_off_module_leaves_the_center(): void
    {
        $url = e(route('purchase.report.show', 'analysis'));

        $this->get(route('reports.center'))->assertOk()->assertSee($url, false);

        app(SettingsService::class)->set('purchase.enabled', false);

        $this->get(route('reports.center'))->assertOk()
            ->assertDontSee($url, false)
            ->assertDontSee('data-map-section="purchase"', false);
    }

    private function member(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }
}
