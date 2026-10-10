<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\ScheduleRunner;
use App\Core\Notifications\TemplateStudio;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationAuditLog;
use App\Models\NotificationRecipientGroup;
use App\Models\NotificationSchedule;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ টেমপ্লেট স্টুডিও প্রতিটা সংস্করণ রাখে — আর দল ও সূচি (মালিকের স্পেক §৪, §৯গ; ধাপ ৩)।
 *
 * ⭐ দাবি:
 *   · অচেনা চলক থাকলে সংরক্ষণ হয় না; প্রতিটা সংরক্ষণ নতুন সংস্করণ, পুরনোটা অবিকল থাকে
 *   · পূর্বরূপে নমুনা মান বসে, আর HTML আঁকা হয় না
 *   · প্রকাশ আলাদা চাবিতে; পুরনো সংস্করণ প্রকাশ মানেই ফেরা, আর দুইটাই নিরীক্ষায়
 *   · পরীক্ষার পাঠানো কেবল নিজের কাছে
 *   · দলের সদস্য আজকের রোল থেকে গোনা হয়; সূচি সময়মতো একবারই পাঠায়, পরের সময় নিজের সময় অঞ্চলে গোনে, একবারের সূচি বন্ধ হয়
 *   · পর্দাগুলো নিজের চাবিতে
 */
final class TheTemplateStudioKeepsEveryVersionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->writer = User::factory()->create(['name' => 'লেখক', 'is_active' => true, 'current_company_id' => $this->company->id]);
        $this->writer->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($this->owner);
    }

    public function test_every_save_is_a_new_version_and_unknown_variables_never_save(): void
    {
        $this->post(route('notification.templates.store'), $this->form(['title_bn' => '{password} ফাঁস']))
            ->assertSessionHasErrors('title_bn');
        $this->assertSame(0, NotificationTemplate::query()->count(), '⛔ অচেনা চলকসহ টেমপ্লেট সংরক্ষিত হলো');

        $this->post(route('notification.templates.store'), $this->form())->assertRedirect();
        $template = NotificationTemplate::query()->where('code', 'due_soon')->firstOrFail();
        $this->put(route('notification.templates.update', $template), $this->form(['title_bn' => '{party}-এর {amount} টাকা বাকি', 'note' => 'শব্দ বদল']))->assertRedirect();

        $versions = NotificationTemplateVersion::query()->where('template_id', $template->id)->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('<b>{amount}</b> টাকা বাকি — {party}', $versions[0]->title_bn, '⛔ পুরনো সংস্করণ বদলে গেল');
        $this->assertNull($template->fresh()->published_version_id, 'সংরক্ষণ মানে প্রকাশ নয়');

        $page = $this->get(route('notification.templates.edit', [$template, 'version' => 1]))->assertOk();
        $page->assertSee('১,২৫,০০০.০০ টাকা বাকি — মেসার্স রহমান ট্রেডার্স')->assertDontSee('<b>১,২৫,০০০.০০</b>', false);
    }

    public function test_publishing_needs_its_own_key_a_second_person_and_an_older_version_rolls_back(): void
    {
        // ⓘ লেখক লেখেন — প্রকাশের চাবি নেই
        $this->grant($this->writer, 'notification.templates');
        $this->actingAs($this->writer->fresh())->post(route('notification.templates.store'), $this->form())->assertRedirect();
        $template = NotificationTemplate::query()->where('code', 'due_soon')->firstOrFail();
        $this->actingAs($this->writer->fresh())->put(route('notification.templates.update', $template), $this->form(['title_bn' => 'দ্বিতীয় লেখা {amount}']))->assertRedirect();
        [$v1, $v2] = NotificationTemplateVersion::query()->where('template_id', $template->id)->orderBy('version')->get()->all();

        $this->actingAs($this->writer->fresh())->get(route('notification.templates.edit', $template))->assertOk()
            ->assertDontSee(route('notification.templates.publish', $template), false);
        $this->actingAs($this->writer->fresh())->post(route('notification.templates.publish', $template), ['version_id' => $v2->id])->assertForbidden();

        // ⭐ চাবি পেলেও নিজের লেখা নিজে প্রকাশ নয় — দ্বিতীয় একজন
        $this->grant($this->writer, 'notification.templates.publish');
        $this->actingAs($this->writer->fresh())->get(route('notification.templates.edit', $template))->assertOk()
            ->assertSee('data-own-version', false)->assertDontSee(route('notification.templates.publish', $template), false);
        $this->actingAs($this->writer->fresh())->post(route('notification.templates.publish', $template), ['version_id' => $v2->id])->assertSessionHas('failed');
        $this->assertNull($template->fresh()->published_version_id, '⛔ লেখক নিজের লেখা নিজেই প্রকাশ করলেন');
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'template_publish')->where('outcome', 'denied')->count());

        $this->actingAs($this->owner)->post(route('notification.templates.publish', $template), ['version_id' => $v2->id])->assertRedirect();
        $this->assertSame($v2->id, (int) $template->fresh()->published_version_id);

        $this->post(route('notification.templates.publish', $template), ['version_id' => $v1->id])->assertRedirect();
        $this->assertSame($v1->id, (int) $template->fresh()->published_version_id, '⛔ আগের সংস্করণে ফেরা গেল না');
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'template_publish')->where('outcome', 'done')->count());
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'template_rollback')->count());

        // ⓘ একা চালানো কোম্পানিতে সুইচ বন্ধ করলে নিজের লেখাও প্রকাশ করা যায়
        app(SettingsService::class)->set('notification.templates_four_eyes', false);
        app(SettingsService::class)->flush();
        $this->actingAs($this->writer->fresh())->post(route('notification.templates.publish', $template), ['version_id' => $v2->id])->assertSessionHas('saved');
        $this->assertSame($v2->id, (int) $template->fresh()->published_version_id);

        // ⓘ অন্য টেমপ্লেটের সংস্করণ এখানে প্রকাশ হয় না
        $this->actingAs($this->owner)->post(route('notification.templates.store'), $this->form(['code' => 'other']))->assertRedirect();
        $foreign = NotificationTemplateVersion::query()->where('template_id', '!=', $template->id)->firstOrFail();
        $this->post(route('notification.templates.publish', $template), ['version_id' => $foreign->id])->assertNotFound();
    }

    public function test_a_test_send_goes_only_to_the_tester(): void
    {
        $this->post(route('notification.templates.store'), $this->form())->assertRedirect();
        $template = NotificationTemplate::query()->where('code', 'due_soon')->firstOrFail();
        $version = $template->latest();

        $this->post(route('notification.templates.test', $template), ['version_id' => $version->id])->assertRedirect();

        $sent = Notification::query()->withoutGlobalScopes()->where('type', 'notification.template_test')->get();
        $this->assertCount(1, $sent);
        $this->assertSame($this->owner->id, (int) $sent[0]->user_id, '⛔ পরীক্ষার খবর অন্য কারও কাছে গেল');
        $this->assertSame('১,২৫,০০০.০০ টাকা বাকি — মেসার্স রহমান ট্রেডার্স', $sent[0]->title);
    }

    public function test_a_schedule_sends_once_on_time_to_todays_group_members_and_moves_on(): void
    {
        $role = CompanyContext::forCompany($this->company->id, fn () => Role::findOrCreate('Due Watch', 'web'));
        CompanyContext::forCompany($this->company->id, fn () => $this->writer->assignRole($role));

        $template = NotificationTemplate::query()->create(['code' => 'morning', 'name' => 'সকালের খবর', 'category' => 'update', 'is_active' => true]);
        $studio = app(TemplateStudio::class);
        $studio->publish($template, $studio->draft($template, ['title_bn' => 'সুপ্রভাত {recipient}', 'title_en' => 'Good morning {recipient}']));

        $this->post(route('notification.groups.store'), ['name' => 'বাকির পাহারা', 'is_active' => '1', 'members' => ['roles' => [$role->id]]])->assertRedirect();
        $group = NotificationRecipientGroup::query()->where('name', 'বাকির পাহারা')->firstOrFail();
        $this->get(route('notification.groups.edit', $group))->assertOk()->assertSee('data-group-member="'.$this->writer->id.'"', false);

        $this->travelTo(CarbonImmutable::parse('2026-10-10 08:00', 'Asia/Dhaka'));
        $this->post(route('notification.schedules.store'), [
            'name' => 'প্রতিদিন সকাল নয়টা', 'template_id' => $template->id, 'group_id' => $group->id, 'priority' => 'low',
            'timezone' => 'Asia/Dhaka', 'recurrence' => 'daily', 'run_at' => '2026-10-10T09:00', 'is_active' => '1',
        ])->assertRedirect();
        $schedule = NotificationSchedule::query()->firstOrFail();
        $this->assertSame('2026-10-10 03:00', $schedule->next_run_at->utc()->format('Y-m-d H:i'), '⛔ সূচির সময় অঞ্চল ভুল গোনা');

        Artisan::call('abos:notifications-schedule');
        $this->assertSame(0, Notification::query()->withoutGlobalScopes()->where('type', 'notification.scheduled')->count(), '⛔ সময়ের আগেই সূচি পাঠাল');

        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00', 'Asia/Dhaka'));
        Artisan::call('abos:notifications-schedule');
        Artisan::call('abos:notifications-schedule');

        $sent = Notification::query()->withoutGlobalScopes()->where('type', 'notification.scheduled')->get();
        $this->assertCount(1, $sent, '⛔ সূচি একবারের বেশি পাঠাল, নয়তো পাঠালই না');
        $this->assertSame($this->writer->id, (int) $sent[0]->user_id);
        $this->assertSame('সুপ্রভাত লেখক', $sent[0]->title);
        $this->assertSame('2026-10-11 09:00', $schedule->fresh()->next_run_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));

        // ⓘ একবারের সূচি পাঠানোর পরে বন্ধ
        // ⓘ নতুন করে পড়া — হাতের মডেলটা পুরনো, তাতে বসালে বদলটা "বদল" বলেই ধরা পড়ত না
        $schedule->fresh()->forceFill(['recurrence' => 'none', 'next_run_at' => now()])->save();
        app(ScheduleRunner::class)->run();
        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertNull($schedule->fresh()->next_run_at);
    }

    public function test_the_studio_groups_schedules_and_quiet_screens_answer_only_to_their_keys(): void
    {
        $this->actingAs($this->writer);
        foreach ([
            route('notification.templates.index'), route('notification.templates.create'), route('notification.groups.index'),
            route('notification.schedules.index'), route('notification.quiet.index'), route('notification.rules.index'),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('notification.quiet.save'), ['quiet_start' => '22:00', 'quiet_end' => '07:00'])->assertForbidden();

        $this->actingAs($this->owner);
        foreach ([
            route('notification.templates.index'), route('notification.groups.index'), route('notification.schedules.index'),
            route('notification.schedules.create'), route('notification.quiet.index'), route('notification.rules.index'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->post(route('notification.quiet.save'), ['quiet_start' => '22:00', 'quiet_end' => '07:00'])->assertRedirect();
        $this->assertSame('22:00', app(SettingsService::class)->get('notification.quiet_start'));
        $this->post(route('notification.quiet.save'), ['quiet_start' => '25:00', 'quiet_end' => '07:00'])->assertSessionHasErrors('quiet_start');
    }

    /** @return array<string, string> */
    private function form(array $over = []): array
    {
        return $over + [
            'code' => 'due_soon', 'name' => 'বাকির খবর', 'category' => 'task', 'is_active' => '1',
            'title_bn' => '<b>{amount}</b> টাকা বাকি — {party}', 'title_en' => '{amount} due — {party}',
            'body_bn' => '{due_date} তারিখের মধ্যে।', 'body_en' => 'By {due_date}.',
        ];
    }

    private function grant(User $user, string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($permission, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
