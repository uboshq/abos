<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হিসাবের ড্যাশবোর্ড — নয়টা বাক্স দুই লাইনে (৫ + ৪)। মালিক, ১ অক্টোবর ২০২৬: *"accounts deshboard e ei
 * box gulo dui line bosaw"*।
 *
 * ⓘ আগে চারটা করে তিন লাইন। ⚠️ সারির সংখ্যা ঠিক হয় বাক্সের সংখ্যা থেকে
 * (`resources/views/dashboard/module.blade.php`): ছয়টা এক লাইনে (মজুদ), নয়-দশটা পাঁচ কলামে; বাকিরা আগের মতো।
 */
final class TheAccountsBoxesSitInTwoRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    public function test_the_nine_boxes_sit_in_two_rows_on_a_wide_screen(): void
    {
        $html = $this->get(route('module.dashboard', ['module' => 'accounts']))->assertOk()->getContent();

        $grid = $this->gridOfTheBoxes($html);

        $this->assertStringContainsString('xl:grid-cols-5', $grid, '⛔ নয়টা বাক্স বড় পর্দায় দুই লাইনে (৫ + ৪) বসার ব্যবস্থা নেই।');
        $this->assertStringNotContainsString('xl:grid-cols-4', $grid, '⛔ বাক্সগুলো এখনো চারটা করে তিন লাইনে।');
    }

    /** সংখ্যার বাক্সগুলোর নিজের ঘর — `data-stat-grid` চিহ্নের ঘরটা; টাইলের বা পাতার অন্য ঘর নয়। */
    private function gridOfTheBoxes(string $html): string
    {
        preg_match('/<div data-stat-grid class="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'প্রস্তুতিটাই ভুল — সংখ্যার বাক্সগুলোর ঘর খুঁজে পাওয়া গেল না।');

        return (string) $m[1];
    }

    /** ⓘ মজুদের ছয়টা আগের মতোই এক লাইনে — নতুন নিয়ম তাকে ছোঁয় না। */
    public function test_the_stock_dashboard_keeps_its_one_row(): void
    {
        $html = $this->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();

        $grid = $this->gridOfTheBoxes($html);

        $this->assertStringContainsString('xl:grid-cols-6', $grid);
        $this->assertStringNotContainsString('xl:grid-cols-5', $grid);
    }
}
