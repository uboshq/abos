<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\Channels\EmailChannel;
use App\Core\Notifications\Channels\WebPushChannel;
use App\Core\Notifications\DeliveryResult;
use App\Core\Notifications\DeliveryService;
use App\Core\Notifications\TemplateStudio;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationJob;
use App\Models\NotificationRecipientGroup;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Modules\Notification\Support\ScriptedChannel;
use Tests\TestCase;

/**
 * ⭐ চাপে ঘণ্টা টেকে, আর গোপন কথা ফাঁস করে না — উৎপাদনের আগের পরীক্ষা (মালিকের স্পেক §১০, §১৩, §১৫, §১৮; ধাপ ৫)।
 *
 * ⭐ দাবি:
 *   · অন্য কোম্পানির নিয়ম, টেমপ্লেট, দল, ডেলিভারি, খবর — কোনোটাই খোলে না (৪০৪), তালিকাতেও আসে না
 *   · টেমপ্লেটে লেখা Blade/PHP/HTML কখনো চলে না — অক্ষর হিসেবেই থাকে বা ছেঁটে যায়, আর পর্দায় escape করা
 *   · প্রোভাইডার ভেঙে পড়লে যে ভুল লেখা থাকে (চেষ্টার লগ, ভুলের খাতা), তাতে চাবি, ঠিকানা, টোকেন নেই
 *   · সব মাধ্যম একসাথে বন্ধ, এমনকি কিউই ভাঙা — তবু ব্যবসার লেনদেন পাকা, ঘণ্টায় খবর
 *   · ৩০০ জনের খবর: একটাই ঘটনা, ৩০০ সারি, কোয়েরি প্রাপক-পিছু সীমিত; আবার-চেষ্টার এক দফা ৫০০-তে থামে
 */
final class TheBellHoldsUnderStrainAndKeepsItsSecretsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = $this->member('কেরানি');
        $this->actingAs($this->owner);
    }

    public function test_another_companys_rules_templates_groups_jobs_and_notifications_never_open(): void
    {
        $other = Company::query()->where('id', '!=', $this->company->id)->firstOrFail();

        [$rule, $template, $group, $job, $event] = CompanyContext::forCompany($other->id, function () use ($other) {
            $template = NotificationTemplate::query()->create(['code' => 'theirs', 'name' => 'ওদের টেমপ্লেট', 'category' => 'task', 'is_active' => true]);
            $rule = NotificationRule::query()->create(['name' => 'ওদের নিয়ম', 'module' => 'approval', 'event' => 'approval.rejected', 'is_active' => true, 'version' => 1]);
            $group = NotificationRecipientGroup::query()->create(['name' => 'ওদের দল', 'members' => [], 'is_active' => true]);
            $theirs = User::factory()->create(['is_active' => true, 'current_company_id' => $other->id]);
            $bell = app(NotificationService::class)->send($theirs, 'approval.rejected', 'ওদের গোপন খবর', evenToSelf: true);
            $job = NotificationJob::query()->create(['company_id' => $other->id, 'notification_id' => $bell->id, 'event_id' => $bell->event_id,
                'user_id' => $theirs->id, 'channel' => 'email', 'status' => NotificationJob::DEAD, 'attempts' => 1, 'max_attempts' => 5]);

            return [$rule, $template, $group, $job, NotificationEvent::query()->findOrFail($bell->event_id)];
        });

        $this->get(route('notification.rules.edit', $rule))->assertNotFound();
        $this->get(route('notification.templates.edit', $template))->assertNotFound();
        $this->get(route('notification.groups.edit', $group))->assertNotFound();
        $this->get(route('notification.center.show', $event))->assertNotFound();
        $this->post(route('notification.deliveries.retry', $job))->assertNotFound();
        $this->post(route('notification.deliveries.cancel', $job), ['resolution' => 'x'])->assertNotFound();
        $this->put(route('notification.rules.update', $rule), ['name' => 'দখল', 'event' => 'approval.rejected'])->assertNotFound();

        foreach ([route('notification.rules.index'), route('notification.templates.index'), route('notification.groups.index'),
            route('notification.center.index'), route('notification.deliveries.failed')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('ওদের')->assertDontSee('data-job="'.$job->id.'"', false);
        }

        $this->assertSame(NotificationJob::DEAD, NotificationJob::query()->withoutGlobalScopes()->find($job->id)->status);
        $this->assertSame('ওদের নিয়ম', NotificationRule::query()->withoutGlobalScopes()->find($rule->id)->name);
    }

    public function test_a_template_never_runs_code_or_renders_html(): void
    {
        $template = NotificationTemplate::query()->create(['code' => 'evil', 'name' => 'বিষ', 'category' => 'task', 'is_active' => true]);
        $studio = app(TemplateStudio::class);
        $version = $studio->draft($template, [
            'title_bn' => '{{ 7*7 }} @php echo 1; @endphp <script>alert(1)</script>{amount}',
            'title_en' => '{!! $secret !!} <img src=x onerror=alert(1)>{amount}',
            'body_bn' => '<?php system("id"); ?> {party}',
        ]);
        $studio->publish($template, $version);
        NotificationRule::query()->create(['name' => 'বিষের নিয়ম', 'module' => 'approval', 'event' => 'approval.rejected', 'is_active' => true,
            'version' => 1, 'template_id' => $template->id]);

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'x', data: ['amount' => '<b>৫০০</b>', 'party' => '{{ $user->password }}']);
        $bell = Notification::query()->where('user_id', $this->clerk->id)->latest('id')->firstOrFail();

        $this->assertSame('{{ 7*7 }} @php echo 1; @endphp alert(1)৫০০', $bell->title, '⛔ টেমপ্লেটের লেখা কোড বা HTML হিসেবে চলল');
        $this->assertStringNotContainsString('49', $bell->title);
        $this->assertStringNotContainsString('<', (string) $bell->body);
        $this->assertStringContainsString('{{ $user->password }}', (string) $bell->body, 'মানটা অক্ষর হিসেবেই থাকে, চলে না');

        $page = $this->actingAs($this->clerk)->get(route('notifications.index'))->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('onerror=alert', false);
    }

    public function test_provider_errors_leave_no_secret_in_the_attempt_log_or_the_error_journal(): void
    {
        config(['mail.default' => 'smtp']);
        $secret = 'Sup3rS3cretPasswordValue1234567890';
        $this->app->instance(EmailChannel::class, new ScriptedChannel([
            new RuntimeException("SMTP auth failed for owner@abos.test with password {$secret} at smtp://user:{$secret}@mail.example.com"),
        ]));

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ভুলের খবর');

        $everything = json_encode([
            DB::table('notification_delivery_attempts')->get(), DB::table('notification_jobs')->get(),
            DB::table('notification_audit_logs')->get(), DB::table('error_events')->get(),
        ]);

        $this->assertStringNotContainsString($secret, (string) $everything, '⛔ প্রোভাইডারের ভুলের সাথে গোপন চাবি খাতায় উঠল');
        $this->assertStringNotContainsString('owner@abos.test', (string) DB::table('notification_delivery_attempts')->get()->toJson());
    }

    public function test_with_every_channel_down_and_even_the_queue_broken_the_business_and_the_bell_go_on(): void
    {
        config(['mail.default' => 'smtp']);
        $this->app->instance(EmailChannel::class, new ScriptedChannel([new RuntimeException('smtp down')]));
        $this->app->instance(WebPushChannel::class, new ScriptedChannel([DeliveryResult::transient('503 push service down', 120)], NotificationChannel::WEB_PUSH));

        $bell = DB::transaction(function () {
            $this->company->forceFill(['legal_name' => 'সব বন্ধেও ব্যবসা'])->save();

            return app(NotificationService::class)->send($this->clerk, 'backup.failed', 'সব মাধ্যম বন্ধ');
        });

        $this->assertSame('সব বন্ধেও ব্যবসা', $this->company->fresh()->legal_name);
        $this->assertNotNull($bell);
        $this->assertTrue(NotificationJob::query()->where('notification_id', $bell->id)->where('status', NotificationJob::RETRYING)->exists());
        $this->assertTrue(NotificationJob::query()->where('notification_id', $bell->id)->where('channel', 'web_push')
            ->where('next_attempt_at', '>=', now()->addSeconds(119))->exists(), '⛔ প্রোভাইডারের Retry-After মানা হলো না');

        // ⓘ কিউ নিজেই ভাঙা — পাঠানো কিছু হয় না, কিন্তু ব্যবসা আর ঘণ্টা চলে
        config(['queue.default' => 'broken', 'queue.connections.broken' => ['driver' => 'nope']]);
        $second = DB::transaction(function () {
            $this->company->forceFill(['legal_name' => 'কিউ ভাঙা, তবু ব্যবসা'])->save();

            return app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'কিউ ভাঙা');
        });
        $this->assertSame('কিউ ভাঙা, তবু ব্যবসা', $this->company->fresh()->legal_name, '⛔ কিউ ভাঙায় ব্যবসার লেনদেন উল্টে গেল');
        $this->assertNotNull($second);
        $this->assertTrue(Notification::query()->whereKey($second->id)->exists());
    }

    public function test_three_hundred_recipients_make_one_event_in_bounded_queries_and_a_retry_round_stops_at_its_limit(): void
    {
        $people = collect(range(1, 300))->map(fn ($i) => $this->member('প্রাপক '.$i));

        DB::enableQueryLog();
        $started = hrtime(true);
        $sent = app(NotificationService::class)->sendMany($people, 'report_ready', 'সবার জন্য একটা খবর', key: 'load:1');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->assertCount(300, $sent);
        $this->assertSame(1, NotificationEvent::query()->where('idempotency_key', 'load:1')->count());
        $this->assertLessThan(300 * 12, $queries, "⛔ প্রাপক-পিছু কোয়েরি লাগামছাড়া: {$queries}");
        $this->assertLessThan(60, $seconds, '⛔ ৩০০ জনের খবরে এক মিনিটের বেশি');

        // ⓘ আবার পাঠালে কিছুই নতুন নয়
        $this->assertCount(0, app(NotificationService::class)->sendMany($people, 'report_ready', 'সবার জন্য একটা খবর', key: 'load:1'));

        // ⓘ এক দফা আবার-চেষ্টা ৫০০-তে থামে — বাকি পরের মিনিটে
        $rows = $sent->take(300)->flatMap(fn ($bell) => [
            ['company_id' => $this->company->id, 'notification_id' => $bell->id, 'event_id' => $bell->event_id, 'user_id' => $bell->user_id,
                'channel' => 'email', 'status' => NotificationJob::RETRYING, 'attempts' => 1, 'max_attempts' => 5, 'next_attempt_at' => now()->subMinute(),
                'public_id' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->company->id, 'notification_id' => $bell->id, 'event_id' => $bell->event_id, 'user_id' => $bell->user_id,
                'channel' => 'web_push', 'status' => NotificationJob::RETRYING, 'attempts' => 1, 'max_attempts' => 5, 'next_attempt_at' => now()->subMinute(),
                'public_id' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
        ])->all();
        DB::table('notification_jobs')->insert($rows);
        config(['queue.default' => 'null']);

        $this->assertSame(500, app(DeliveryService::class)->retryDue()['retried'], '⛔ এক দফায় ৫০০-র বেশি তোলা হলো');
    }

    private function member(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }
}
