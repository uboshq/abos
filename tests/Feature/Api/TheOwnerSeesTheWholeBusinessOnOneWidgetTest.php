<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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
 * ⭐ মালিকের পূর্ণ-পর্দা উইজেট — এক নজরে পুরো ব্যবসা ([[OwnerBoardSummary]]; মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * দাবি: আজকের সংখ্যা ফোনের "আজ কেমন গেল" দরজার হুবহু একই; দলের অন্য উইজেট (লক্ষ্য, লাভ …) যা মালিক পান তা পূর্ণ-পর্দায়ও
 * বসে, অক্ষরে অক্ষরে এক; আর নির্বাহী-চাবি ছাড়া কারো উত্তরে পূর্ণ-পর্দা নেই।
 */
final class TheOwnerSeesTheWholeBusinessOnOneWidgetTest extends TestCase
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
        WidgetSummaries::$testing = [ProbeTarget::class];
    }

    protected function tearDown(): void
    {
        WidgetSummaries::$testing = [];
        parent::tearDown();
    }

    public function test_the_board_carries_todays_figures_exactly_as_the_today_door_and_the_teams_parts_as_they_are(): void
    {
        $board = $this->as($this->owner)->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets.owner_board');
        $this->assertNotNull($board, 'প্রস্তুতিটাই ভুল — মালিকের পূর্ণ-পর্দা নেই।');

        $today = $this->as($this->owner)->getJson('/api/v1/dashboard/today')->assertOk()->json();
        foreach (['sales', 'collections', 'money', 'dues', 'approvals'] as $part) {
            $this->assertSame($today[$part] ?? null, $board['today'][$part] ?? null, "⛔ {$part}: পূর্ণ-পর্দা আর আজকের পাতা আলাদা কথা বলে।");
        }
        $this->assertArrayHasKey('sales', $board['today'], 'প্রস্তুতিটাই ভুল — মালিক বিক্রি দেখেন।');

        $target = $this->as($this->owner)->getJson('/api/v1/widgets/summary?keys=target')->json('widgets.target');
        $this->assertSame($target, $board['parts']['target'] ?? null, '⛔ লক্ষ্যের ছোট উইজেট আর পূর্ণ-পর্দা আলাদা।');
        $this->assertSame('/owner', $board['link']);
    }

    public function test_nobody_without_the_executive_key_gets_the_board(): void
    {
        $someone = User::query()->whereKeyNot($this->owner->id)->get()->first(fn (User $u) => ! $u->can('executive.view'));
        $this->assertNotNull($someone, 'প্রস্তুতিটাই ভুল — চাবি-ছাড়া কেউ নেই।');

        $widgets = $this->as($someone)->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets');
        $this->assertArrayNotHasKey('owner_board', $widgets, '⛔ নির্বাহী-চাবি ছাড়াই মালিকের পূর্ণ-পর্দা গেল।');
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}

final class ProbeTarget implements WidgetSummary
{
    public function key(): string
    {
        return 'target';
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
        return ['month' => '2026-10', 'target' => '20000000.00', 'achieved' => '13600000.00', 'days_left' => 12];
    }
}
