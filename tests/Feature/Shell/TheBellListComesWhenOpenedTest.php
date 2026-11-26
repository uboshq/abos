<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ঘণ্টার তালিকা তিন ABOS রূপে খুললে আনা (মালিকের নমুনা, ঘণ্টা ও রেল দ্রুত, ২৬ নভেম্বর ২০২৬) — প্রতিটা পাতা হালকা, কিছুই হারায় না।
 *
 * দাবি: ABOS রূপে পাতায় তালিকা নেই, আনার ঠিকানা আছে, আর গোনা পাতাতেই; `?part=bell` হুবহু তালিকা দেয়, কেবল নিজের খবর;
 * বাকি রূপে তালিকা পাতাতেই আগের মতো।
 */
final class TheBellListComesWhenOpenedTest extends TestCase
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
        app(NotificationService::class)->send($this->owner, 'backup.failed', 'রাতের ব্যাকআপ ব্যর্থ — ঘণ্টার পরীক্ষা');
    }

    public function test_the_abos_looks_fetch_the_list_and_keep_the_count(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $this->owner->forceFill(['ui' => $look])->save();
            $html = (string) $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();
            $from = (int) strpos($html, 'data-notification-bell');
            $bell = substr($html, $from, max(0, (int) strpos($html, '</header>', $from) - $from));

            $this->assertStringContainsString('data-bell-url="'.e(route('notifications.index', ['part' => 'bell'])).'"', $bell, "⛔ {$look}: ঘণ্টায় তালিকা আনার ঠিকানা নেই");
            $this->assertStringNotContainsString('ঘণ্টার পরীক্ষা', $bell, "⛔ {$look}: তালিকা তবু পাতার সাথে এল");
            $this->assertMatchesRegularExpression('/data-count="[1-9]\d*"/', $bell, "⛔ {$look}: গোনা পাতায় নেই — লাল সংখ্যা দেরিতে আসত");
        }

        $list = (string) $this->actingAs($this->owner)->get(route('notifications.index', ['part' => 'bell']))->assertOk()->getContent();
        $this->assertStringContainsString('ঘণ্টার পরীক্ষা', $list, '⛔ আনা তালিকায় খবরটা নেই');
        $this->assertStringContainsString('data-notify-all', $list, '⛔ "সব বিজ্ঞপ্তি" হারাল');
        $this->assertStringNotContainsString('<html', $list, '⛔ গোটা পাতা এল, ঘণ্টার টুকরা নয়');
    }

    public function test_other_looks_keep_the_list_in_the_page(): void
    {
        $this->owner->forceFill(['ui' => 'tiles'])->save();
        $html = (string) $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-bell-url=', $html, '⛔ SAP রূপেও তালিকা দেরিতে — বাকি রূপ আগের মতো থাকার কথা');
        $this->assertStringContainsString('ঘণ্টার পরীক্ষা', $html, '⛔ SAP রূপে ঘণ্টার খবর হারাল');
    }

    public function test_the_part_shows_only_ones_own_news(): void
    {
        $other = User::query()->where('email', '!=', 'owner@abos.test')->where('is_active', true)->firstOrFail();
        $list = (string) $this->actingAs($other)->get(route('notifications.index', ['part' => 'bell']))->assertOk()->getContent();

        $this->assertStringNotContainsString('ঘণ্টার পরীক্ষা', $list, '⛔ অন্যের ঘণ্টার খবর দেখা গেল');
    }
}
