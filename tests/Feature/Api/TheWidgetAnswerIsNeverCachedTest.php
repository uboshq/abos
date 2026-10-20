<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ উইজেটের উত্তর কোনো ক্যাশে থাকে না — উইজেটের নিরাপত্তা-পর্যালোচনা ৮ (cb, ২০ অক্টোবর ২০২৬; [[WidgetSummaryController]])।
 *
 * দাবি (ফোনের আসল দরজা দিয়ে): পুরো উত্তর আর ৩০৪ — দুটোতেই `Cache-Control`-এ `no-store` আর `private`; ETag আগের মতোই কাজ করে।
 */
final class TheWidgetAnswerIsNeverCachedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_answer_and_the_304_both_say_no_store_and_private(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);

        $full = $this->getJson('/api/v1/widgets/summary')->assertOk();
        $this->assertNoStore((string) $full->headers->get('Cache-Control'), 'পুরো উত্তর');

        $etag = (string) $full->headers->get('ETag');
        $this->assertNotSame('', $etag, 'প্রস্তুতিটাই ভুল — ETag নেই');

        $again = $this->getJson('/api/v1/widgets/summary', ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertNoStore((string) $again->headers->get('Cache-Control'), '৩০৪');
    }

    private function assertNoStore(string $header, string $which): void
    {
        $this->assertStringContainsString('no-store', $header, "⛔ {$which}: ক্যাশে রাখা চলে — হাতের নগদ আর লাভ proxy বা ফোনের ক্যাশে থাকত");
        $this->assertStringContainsString('private', $header, "⛔ {$which}: ভাগের ক্যাশে রাখা চলে");
    }
}
