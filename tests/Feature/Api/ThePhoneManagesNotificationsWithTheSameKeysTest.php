<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationAuditLog;
use App\Models\NotificationJob;
use App\Models\NotificationPreference;
use App\Models\NotificationRule;
use App\Models\NotificationRuleVersion;
use App\Models\NotificationTemplate;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বিজ্ঞপ্তির বাকি API — পর্দার সমান চাবি, একই যাচাই (মালিকের স্পেক §১২; বিজ্ঞপ্তি ব্যবস্থাপনা)।
 *
 * ⭐ দাবি:
 *   · নিজের খবরের বিস্তারিত — অন্যেরটা ৪০৪
 *   · নিজের পছন্দ পড়া আর বদলানো — পর্দার সমান যাচাই (ভুলে ৪২২)
 *   · নিয়ম: তালিকা, তৈরি, বদল (নতুন সংস্করণ), শুকনো পরীক্ষা — চাবি ছাড়া ৪০৩, অন্য কোম্পানির নিয়ম ৪০৪
 *   · টেমপ্লেট: তৈরি; প্রকাশ আলাদা চাবিতে আর লেখক নিজে নয়
 *   · ডেলিভারি: তালিকা, হাতে আবার চেষ্টা; স্বাস্থ্য — নিজের চাবিতে; পাতায় ভাগ
 */
final class ThePhoneManagesNotificationsWithTheSameKeysTest extends TestCase
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
        $this->clerk = User::factory()->create(['name' => 'কেরানি', 'is_active' => true, 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_my_notification_details_and_my_preferences(): void
    {
        $this->actingAs($this->owner);
        $mine = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'আমার খবর', 'বিস্তারিত');
        $theirs = app(NotificationService::class)->send($this->owner, 'approval.rejected', 'মালিকের খবর', evenToSelf: true);

        $this->as($this->clerk)->getJson('/api/v1/notifications/'.$mine->public_id)->assertOk()
            ->assertJsonPath('data.title', 'আমার খবর')->assertJsonPath('data.body', 'বিস্তারিত');
        $this->as($this->clerk)->getJson('/api/v1/notifications/'.$theirs->public_id)->assertNotFound();

        $this->as($this->clerk)->getJson('/api/v1/notification-preferences')->assertOk()
            ->assertJsonPath('data.frequency', 'instant')->assertJsonPath('data.channels.email', true);

        $this->as($this->clerk)->putJson('/api/v1/notification-preferences', [
            'channels' => ['web_push'], 'frequency' => 'daily', 'digest_hour' => 8,
            'quiet_enabled' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00',
        ])->assertOk()->assertJsonPath('data.channels.email', false)->assertJsonPath('data.frequency', 'daily');
        $this->assertSame('daily', NotificationPreference::query()->where('user_id', $this->clerk->id)->value('frequency'));

        $this->as($this->clerk)->putJson('/api/v1/notification-preferences', ['frequency' => 'hourly'])
            ->assertStatus(422)->assertJsonValidationErrors('frequency');
    }

    public function test_rules_need_their_key_save_versions_and_dry_run_without_sending(): void
    {
        $this->as($this->clerk)->getJson('/api/v1/notification-rules')->assertForbidden();
        $this->as($this->clerk)->postJson('/api/v1/notification-rules', ['name' => 'x', 'event' => 'approval.rejected'])->assertForbidden();

        $created = $this->as($this->owner)->postJson('/api/v1/notification-rules', [
            'name' => 'বড় অঙ্ক', 'event' => 'approval.rejected', 'is_active' => true,
            'conditions' => [['field' => 'amount', 'op' => 'gt', 'value' => '100000']],
            'recipients' => ['users' => [$this->clerk->id]],
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        $this->as($this->owner)->putJson('/api/v1/notification-rules/'.$created, [
            'name' => 'বড় অঙ্ক', 'event' => 'approval.rejected', 'is_active' => true,
            'conditions' => [['field' => 'amount', 'op' => 'gt', 'value' => '200000']],
            'recipients' => ['users' => [$this->clerk->id]],
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(2, NotificationRuleVersion::query()->where('rule_id', $created)->count());

        $this->as($this->owner)->postJson('/api/v1/notification-rules', ['name' => 'x', 'event' => 'not.a.kind'])
            ->assertStatus(422)->assertJsonValidationErrors('event');

        $before = Notification::query()->count();
        $this->as($this->owner)->postJson('/api/v1/notification-rules/'.$created.'/test', ['values' => ['amount' => '250000']])
            ->assertOk()->assertJsonPath('data.matches', true)->assertJsonPath('data.would_reach.0.name', 'কেরানি');
        $this->assertSame($before, Notification::query()->count(), '⛔ শুকনো পরীক্ষা খবর পাঠাল');

        $this->as($this->owner)->getJson('/api/v1/notification-rules?per_page=1')->assertOk()
            ->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.total', 1);

        // ⓘ অন্য কোম্পানির নিয়ম ৪০৪
        $other = Company::query()->where('id', '!=', $this->company->id)->firstOrFail();
        $theirs = CompanyContext::forCompany($other->id, fn () => NotificationRule::query()->create([
            'name' => 'ওদের', 'module' => 'approval', 'event' => 'approval.rejected', 'is_active' => true, 'version' => 1,
        ]));
        $this->as($this->owner)->putJson('/api/v1/notification-rules/'.$theirs->id, ['name' => 'দখল', 'event' => 'approval.rejected'])->assertNotFound();
        $this->assertSame('ওদের', NotificationRule::query()->withoutGlobalScopes()->find($theirs->id)->name);
    }

    public function test_templates_publish_by_a_second_person_and_deliveries_and_health_answer_to_their_keys(): void
    {
        $this->grant($this->clerk, 'notification.templates');
        $this->grant($this->clerk, 'notification.templates.publish');

        $id = $this->as($this->clerk)->postJson('/api/v1/notification-templates', [
            'code' => 'api_tpl', 'name' => 'API টেমপ্লেট', 'category' => 'task', 'title_bn' => '{amount} বাকি', 'title_en' => '{amount} due',
        ])->assertCreated()->assertJsonPath('data.latest_version', 1)->json('data.id');

        $this->as($this->clerk)->postJson('/api/v1/notification-templates', [
            'code' => 'bad', 'name' => 'x', 'category' => 'task', 'title_bn' => '{password}', 'title_en' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('title_bn');

        $this->as($this->clerk)->postJson('/api/v1/notification-templates/'.$id.'/publish', ['version' => 1])->assertForbidden();
        $this->assertNull(NotificationTemplate::query()->find($id)->published_version_id, '⛔ লেখক API দিয়ে নিজের লেখা প্রকাশ করলেন');

        $this->as($this->owner)->postJson('/api/v1/notification-templates/'.$id.'/publish', ['version' => 1])->assertOk()
            ->assertJsonPath('data.published_version', 1);

        $this->as($this->clerk)->getJson('/api/v1/notification-deliveries')->assertForbidden();
        $this->as($this->clerk)->getJson('/api/v1/notification-health')->assertForbidden();

        $this->actingAs($this->owner);
        $bell = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ডেলিভারির খবর');
        $job = NotificationJob::query()->create(['company_id' => $this->company->id, 'notification_id' => $bell->id, 'event_id' => $bell->event_id,
            'user_id' => $this->clerk->id, 'channel' => 'email', 'status' => NotificationJob::DEAD, 'attempts' => 5, 'max_attempts' => 5]);

        $this->as($this->owner)->getJson('/api/v1/notification-deliveries?status=dead')->assertOk()
            ->assertJsonPath('data.0.id', (string) $job->public_id)->assertJsonPath('data.0.status', 'dead');
        $this->as($this->owner)->postJson('/api/v1/notification-deliveries/'.$job->public_id.'/retry')->assertOk();
        // ⓘ আবার চেষ্টা হলো (পরীক্ষায় ইমেইল সংযুক্ত নয়, তাই আবার ব্যর্থ-তালিকায়) — হাতের কাজটা খাতায়
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'delivery_retry')->count());
        $this->assertGreaterThan(5, (int) $job->fresh()->attempts, '⛔ হাতে আবার চেষ্টায় কোনো চেষ্টা হলো না');

        $this->as($this->owner)->getJson('/api/v1/notification-health')->assertOk()
            ->assertJsonPath('data.0.channel', 'email')->assertJsonCount(4, 'data');
    }

    private function as(User $user): self
    {
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }

    private function grant(User $user, string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($permission, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
