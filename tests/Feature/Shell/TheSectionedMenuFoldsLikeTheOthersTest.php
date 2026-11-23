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
 * ⛔ মালিক, ২৩ নভেম্বর ২০২৬: "মেনু এবং সাবমেনু গুলা আগের মতো রয়ে গেছে। এগুলা ফোল্ড হয়ে থাকার কথা, হচ্ছে না" — সিস্টেম
 * প্রশাসনের মেনু ভাগে ভাগে সাজানো (`panel_sections`), আর ভাঁজ কেবল দলে সাজানো মডিউলে চলত। এখন তিন ABOS রূপে প্রতিটা ভাগ
 * এক ভাঁজের দল — এই পাতার সারির দল খোলা, বাকিরা বন্ধ। ⛔ SAP আর Dynamic-এ আগের মতোই ভাগের ছোট শিরোনাম, কোনো ভাঁজ নয়।
 */
final class TheSectionedMenuFoldsLikeTheOthersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    public function test_the_system_menu_folds_by_section_in_the_abos_looks(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $html = $this->pageAs($look);

            $this->assertStringContainsString('data-menu-fold-nav=', $html, "{$look}: the system menu does not fold");
            $this->assertGreaterThan(1, preg_match_all('/<button[^>]*data-menu-fold="sec-/', $html), "{$look}: the sections are not fold groups");
            $this->assertStringContainsString('data-menu-fold-here', $html, "{$look}: the section of this page is not open");
        }
    }

    public function test_sap_and_dynamic_keep_the_plain_section_headings(): void
    {
        foreach (['tiles', 'dynamic'] as $look) {
            $this->assertStringNotContainsString('data-menu-fold="sec-', $this->pageAs($look), "{$look}: the fold leaked into a look that must stay original");
        }
    }

    private function pageAs(string $look): string
    {
        $this->owner->forceFill(['ui' => $look])->save();

        return (string) $this->actingAs($this->owner->fresh())->get('/system/users')->assertOk()->getContent();
    }
}
