<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\RuleEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NotificationRule;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ একটা নিয়ম জরুরি খবরের গুরুত্ব নামিয়ে দিতে পারত (cb-র রিভিউ, cloud/notification-management মেশানোর আগে, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ `backup.failed` জরুরি; কেউ নিয়মে প্রাপক যোগ করে গুরুত্বের ঘরে "উঁচু" বাছলে খবরটা উঁচু হয়ে যেত — নীরব সময় আর দিনের সীমা তখন
 * আটকাত। এখন নিয়ম কেবল বাড়ায়: জরুরি জরুরিই থাকে; সাধারণ খবরে "জরুরি" নিয়ম সেটাকে জরুরি করে।
 */
final class ARuleNeverLowersACriticalNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rule_can_raise_a_notices_priority_but_never_lower_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $rule = fn (string $event, string $priority) => NotificationRule::query()->create(['name' => 'p-'.$event, 'module' => 'system',
            'event' => $event, 'priority' => $priority, 'is_active' => true, 'version' => 1]);
        $rule('backup.failed', 'high');
        $rule('approval.approved', 'critical');

        $engine = app(RuleEngine::class);
        $critical = $engine->plan('backup.failed', null, 'critical', []);
        $this->assertNotSame('high', $critical['priority'], '⛔ নিয়ম জরুরি খবরকে "উঁচু"-তে নামাল।');
        $this->assertSame('critical', $critical['priority'] ?? 'critical');

        $raised = $engine->plan('approval.approved', null, 'normal', []);
        $this->assertSame('critical', $raised['priority'], '⛔ নিয়ম সাধারণ খবরকে জরুরি করতে পারল না।');
    }
}
