<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Dashboard\CardColours;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ড্যা-৭ — ফোনের অ্যাপে ওয়েবের নতুন ড্যাশবোর্ডের একই সাজ (মালিক, ৮ নভেম্বর ২০২৬, নমুনা "ok"): মাথার দুই রং, উপরের চার
 * কার্ডের রং, "নজরে রাখুন", দ্রুত কাজের বোতাম, আর প্রতিটা অঙ্কের পেছনের ঠিকানা — সব ওয়েবের একই নিয়মে (module-v2.blade.php)।
 */
final class ThePhoneDashboardWearsTheWebsNewLookTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sales_dashboard_reaches_the_phone_with_its_colours_watch_list_buttons_and_links(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        $web = app(DashboardEngine::class)->for('sales', $owner);

        Sanctum::actingAs($owner->fresh(), [AuthController::APP]);
        $answer = $this->getJson('/api/v1/dashboard/sales')->assertOk();

        $this->assertSame(['blue', 'sky'], $answer->json('colours.head'), '⛔ ফোনের বিক্রয়ের মাথা ওয়েবের রঙে নয় (নীল-আকাশি)।');
        $this->assertSame(CardColours::names('sales', array_map(fn ($s) => $s->tone, array_slice($web->stats, 0, 4))), $answer->json('colours.cards'),
            '⛔ উপরের চার কার্ডের রং ওয়েবের নিয়মে নয়।');
        $this->assertCount(min(4, count($web->stats)), (array) $answer->json('colours.cards'), '⛔ চার কার্ডের রং গোনা ভুল।');

        $this->assertSame(array_map(fn ($t) => $t->href, $web->tiles), array_column((array) $answer->json('tiles'), 'href'),
            '⛔ ফোনে দ্রুত কাজের বোতাম ওয়েবের মতো নয়।');

        $webHrefs = array_values(array_filter(array_map(fn ($s) => $s->href, $web->stats)));
        $this->assertNotEmpty($webHrefs, 'দাবির ভিত্তি নেই — ওয়েবের কার্ডে ঠিকানা নেই।');
        $this->assertSame($webHrefs, array_values(array_filter(array_column((array) $answer->json('stats'), 'href'))),
            '⛔ ফোনের কার্ডে চাপলে পেছনের তালিকা খোলার ঠিকানা নেই।');

        $this->assertIsArray($answer->json('watch'), '⛔ "নজরে রাখুন" ফোনে আসেনি।');
        foreach ((array) $answer->json('watch') as $row) {
            $this->assertSame(['label', 'value', 'tone', 'href'], array_keys($row), '⛔ "নজরে রাখুন"-এর সারির ধরন ভুল।');
            $this->assertMatchesRegularExpression('/[1-9১-৯]/u', (string) $row['value'], '⛔ "নজরে রাখুন"-এ শূন্যের সারি — ওয়েবে শূন্য আসে না।');
        }
        $expected = collect($web->reminders)->filter(fn ($w) => $w->group === 'todo' && (int) preg_replace('/\D/', '', strtr((string) $w->value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'])) > 0)
            ->map(fn ($w) => $w->label)->values()->all();
        $this->assertSame($expected, array_column((array) $answer->json('watch'), 'label'), '⛔ ফোনের "নজরে রাখুন" ওয়েবের তালিকার সাথে মেলে না।');
    }
}
