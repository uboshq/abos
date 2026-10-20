<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Widgets\WidgetSummaries;
use App\Core\Widgets\WidgetSummary;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের হোম-স্ক্রিন উইজেটের এক দরজা — `GET /api/v1/widgets/summary` (মোবাইলের পরিকল্পনা ৪, অংশ ৯; মালিকের আদেশ,
 * ২০ অক্টোবর ২০২৬; [[WidgetSummaryController]])।
 *
 * দাবি: যার চাবি নেই বা যার মডিউল ফোনে বন্ধ, তার উইজেট উত্তরে থাকেই না; `keys` দিলে কেবল সেগুলো; তথ্য না বদলালে 304;
 * আর দরজা কিছু লেখে না।
 */
final class TheWidgetsAskOneDoorForEverythingTest extends TestCase
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
        WidgetSummaries::$testing = [ProbeSales::class, ProbeNobody::class, ProbeEveryone::class];
    }

    protected function tearDown(): void
    {
        WidgetSummaries::$testing = [];
        parent::tearDown();
    }

    public function test_a_widget_comes_only_to_someone_allowed_and_only_while_its_module_is_on_the_phone(): void
    {
        $widgets = $this->phone()->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets');
        $this->assertSame(['figure' => '125000.00'], $widgets['probe_sales'] ?? null, 'প্রস্তুতিটাই ভুল — মালিক বিক্রি দেখতে পারেন।');
        $this->assertArrayNotHasKey('probe_nobody', $widgets, '⛔ চাবি ছাড়াই উইজেটের তথ্য গেল।');
        $this->assertSame(['unread' => 2], $widgets['probe_everyone'] ?? null, 'চাবি-ছাড়া উইজেট (null) সাইন-ইন করা সবার');

        $this->switch('sales', false);
        $widgets = $this->phone()->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets');
        $this->assertArrayNotHasKey('probe_sales', $widgets, '⛔ ফোনে বিক্রয় বন্ধ, তবু বিক্রির উইজেট।');
    }

    public function test_asked_keys_narrow_the_answer(): void
    {
        $this->assertSame([], $this->phone()->getJson('/api/v1/widgets/summary?keys=other')->assertOk()->json('widgets'));
        $this->assertArrayHasKey('probe_sales', $this->phone()->getJson('/api/v1/widgets/summary?keys=other,probe_sales')->json('widgets'));
    }

    public function test_the_same_facts_answer_304_without_a_body(): void
    {
        $first = $this->phone()->getJson('/api/v1/widgets/summary')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $again = $this->phone()->getJson('/api/v1/widgets/summary', ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());

        ProbeSales::$figure = '125000.50';
        $this->phone()->getJson('/api/v1/widgets/summary', ['If-None-Match' => $etag])->assertOk();
        ProbeSales::$figure = '125000.00';
    }

    public function test_signed_out_gets_nothing(): void
    {
        $this->getJson('/api/v1/widgets/summary')->assertUnauthorized();
    }

    private function switch(string $module, bool $on): void
    {
        app(SettingsService::class)->set(PhoneModules::key($module), $on);
        app()->forgetInstance(SettingsService::class);
        app()->forgetInstance(PhoneModules::class);
    }

    private function phone(): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this;
    }
}

final class ProbeSales implements WidgetSummary
{
    public static string $figure = '125000.00';

    public function key(): string
    {
        return 'probe_sales';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function permission(): string
    {
        return 'sales.invoice.view';
    }

    public function data(User $user): array
    {
        return ['figure' => self::$figure];
    }
}

final class ProbeNobody implements WidgetSummary
{
    public function key(): string
    {
        return 'probe_nobody';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function permission(): string
    {
        return 'no.such.permission';
    }

    public function data(User $user): array
    {
        return ['secret' => 'x'];
    }
}

final class ProbeEveryone implements WidgetSummary
{
    public function key(): string
    {
        return 'probe_everyone';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function data(User $user): array
    {
        return ['unread' => 2];
    }
}
