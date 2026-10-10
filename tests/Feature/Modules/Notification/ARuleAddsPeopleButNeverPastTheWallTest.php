<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\TemplateStudio;
use App\Core\Services\DataScope;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationAuditLog;
use App\Models\NotificationRule;
use App\Models\NotificationRuleVersion;
use App\Models\NotificationSuppression;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ নিয়ম মানুষ যোগ করে, কিন্তু কখনো দেয়াল পেরিয়ে নয় (মালিকের স্পেক §৮, §১৩; ধাপ ৩)।
 *
 * ⭐ দাবি:
 *   · শর্ত মিললে নিয়ম প্রাপক যোগ করে (মানুষ, রোল); না মিললে নয়; টাকার তুলনা bcmath-এ, বাংলা অঙ্কেও
 *   · অন্য শাখায় আটকানো কেউ নিয়মে থাকলেও সেই শাখার কাগজের খবর পান না
 *   · বন্ধ নিয়ম, বা কার্যকর সময়ের বাইরের নিয়ম খাটে না
 *   · নিয়মের গুরুত্ব আর টেমপ্লেট খবরে বসে — প্রত্যেকে নিজের ভাষায়, HTML ছাঁটা
 *   · একই কাগজের একই খবর নিয়মের সময়ের মধ্যে আবার নয়, আর আটকানোটা লেখা থাকে
 *   · পর্দা থেকে নিয়ম লেখা: সংস্করণ বাড়ে, নিরীক্ষায় যায়; শুকনো পরীক্ষা কিছু পাঠায় না; চাবি ছাড়া ৪০৩
 */
final class ARuleAddsPeopleButNeverPastTheWallTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    private User $cfo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = $this->member('কেরানি');
        $this->cfo = $this->member('হিসাবপ্রধান', 'en');
        $this->actingAs($this->owner);
    }

    public function test_a_rule_adds_people_only_when_its_conditions_hold(): void
    {
        $role = CompanyContext::forCompany($this->company->id, fn () => Role::findOrCreate('Treasury Watch', 'web'));
        $watcher = $this->member('কোষাগার');
        CompanyContext::forCompany($this->company->id, fn () => $watcher->assignRole($role));

        $this->rule(['conditions' => [['field' => 'amount', 'op' => 'gt', 'value' => '100000']],
            'recipients' => ['users' => [$this->cfo->id], 'roles' => [$role->id]]]);

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'বড় অঙ্ক ফেরত', data: ['amount' => '১,২৫,০০০.০০']);
        $this->assertTrue($this->got($this->cfo, 'বড় অঙ্ক ফেরত'), '⛔ শর্ত মিলল, তবু নিয়মের মানুষ পেলেন না');
        $this->assertTrue($this->got($watcher, 'বড় অঙ্ক ফেরত'), '⛔ রোলের মানুষ পেলেন না');
        $this->assertTrue($this->got($this->clerk, 'বড় অঙ্ক ফেরত'), '⛔ নিয়ম মডিউলের নিজের প্রাপককে সরিয়ে দিল');

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ছোট অঙ্ক ফেরত', data: ['amount' => '5000']);
        $this->assertFalse($this->got($this->cfo, 'ছোট অঙ্ক ফেরত'), '⛔ শর্ত না মিললেও নিয়ম খাটল');

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'অঙ্ক ছাড়া ফেরত');
        $this->assertFalse($this->got($this->cfo, 'অঙ্ক ছাড়া ফেরত'), '⛔ মান না থাকলেও শর্ত "মিলল"');
    }

    public function test_a_rule_never_reaches_someone_walled_off_from_the_papers_branch(): void
    {
        $this->limit($this->cfo, 'NTK');
        $this->rule(['recipients' => ['users' => [$this->cfo->id]]]);

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'এই শাখার কাগজ', about: $this->paper('MMS'));
        $this->assertFalse($this->got($this->cfo, 'এই শাখার কাগজ'), '⛔ অন্য শাখায় আটকানো মানুষ নিয়মের জোরে এই শাখার খবর পেলেন');

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'তাঁর শাখার কাগজ', about: $this->paper('NTK'));
        $this->assertTrue($this->got($this->cfo, 'তাঁর শাখার কাগজ'));
    }

    public function test_an_inactive_or_out_of_date_rule_does_not_apply(): void
    {
        $rule = $this->rule(['recipients' => ['users' => [$this->cfo->id]], 'is_active' => false]);
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'বন্ধ নিয়ম');
        $this->assertFalse($this->got($this->cfo, 'বন্ধ নিয়ম'), '⛔ বন্ধ নিয়ম খাটল');

        $rule->forceFill(['is_active' => true, 'effective_from' => now()->addDay()->toDateString()])->save();
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'আগামীকালের নিয়ম');
        $this->assertFalse($this->got($this->cfo, 'আগামীকালের নিয়ম'), '⛔ কার্যকর হওয়ার আগেই নিয়ম খাটল');

        $rule->forceFill(['effective_from' => now()->subDays(10)->toDateString(), 'effective_to' => now()->subDay()->toDateString()])->save();
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'মেয়াদ পেরোনো নিয়ম');
        $this->assertFalse($this->got($this->cfo, 'মেয়াদ পেরোনো নিয়ম'), '⛔ মেয়াদ পেরোনো নিয়ম খাটল');
    }

    public function test_the_rules_priority_and_template_reach_each_person_in_their_own_language(): void
    {
        $template = NotificationTemplate::query()->create(['code' => 'big_return', 'name' => 'বড় ফেরত', 'category' => 'approval', 'is_active' => true]);
        $studio = app(TemplateStudio::class);
        $v1 = $studio->draft($template, [
            'title_bn' => '{amount} টাকার কাগজ ফেরত — {party}', 'title_en' => 'Returned: {amount} — {party}',
            'body_bn' => 'প্রিয় {recipient}, দেখুন।', 'body_en' => 'Dear {recipient}, please look.',
        ]);
        $studio->publish($template, $v1);

        $this->rule(['recipients' => ['users' => [$this->cfo->id]], 'priority' => 'critical', 'template_id' => $template->id]);

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'মডিউলের লেখা',
            data: ['amount' => '১,২৫,০০০', 'party' => '<script>alert(1)</script>রহমান']);

        $bn = Notification::query()->where('user_id', $this->clerk->id)->latest('id')->firstOrFail();
        $en = Notification::query()->where('user_id', $this->cfo->id)->latest('id')->firstOrFail();

        $this->assertSame('১,২৫,০০০ টাকার কাগজ ফেরত — alert(1)রহমান', $bn->title, '⛔ টেমপ্লেট বসল না, নয়তো HTML থেকে গেল');
        $this->assertSame('Returned: ১,২৫,০০০ — alert(1)রহমান', $en->title, '⛔ ইংরেজি পাঠক নিজের ভাষার লেখা পেলেন না');
        $this->assertSame('Dear '.$this->cfo->name.', please look.', $en->body);
        $this->assertSame('critical', $bn->priority, '⛔ নিয়মের গুরুত্ব বসল না');
        $this->assertSame($v1->id, (int) $bn->event->template_version_id);
    }

    public function test_the_same_notice_about_the_same_paper_is_not_repeated_inside_the_cooldown(): void
    {
        $this->rule(['cooldown_minutes' => 60]);
        $paper = $this->paper('MMS');

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'প্রথমবার', about: $paper);
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'আবার, দশ মিনিটে', about: $paper);
        $this->assertTrue($this->got($this->clerk, 'প্রথমবার'));
        $this->assertFalse($this->got($this->clerk, 'আবার, দশ মিনিটে'), '⛔ একই কাগজের একই খবর সময়ের মধ্যে আবার গেল');
        $this->assertSame(1, NotificationSuppression::query()->where('reason', 'cooldown')->where('user_id', $this->clerk->id)->count());

        $this->travel(61)->minutes();
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'এক ঘণ্টা পরে', about: $paper);
        $this->assertTrue($this->got($this->clerk, 'এক ঘণ্টা পরে'));
    }

    public function test_rules_are_written_on_screen_versioned_audited_and_dry_run_without_sending(): void
    {
        $this->actingAs($this->clerk)->get(route('notification.rules.index'))->assertForbidden();
        $this->actingAs($this->clerk)->post(route('notification.rules.store'), ['name' => 'x', 'event' => 'approval.rejected'])->assertForbidden();

        $this->actingAs($this->owner)->get(route('notification.rules.create'))->assertOk();
        $this->post(route('notification.rules.store'), [
            'name' => 'বড় অঙ্ক হিসাবপ্রধানকে', 'event' => 'approval.rejected', 'is_active' => '1',
            'conditions' => [['field' => 'amount', 'op' => 'gte', 'value' => '100000'], ['field' => '', 'op' => '', 'value' => '']],
            'recipients' => ['users' => [$this->cfo->id]],
        ])->assertRedirect();

        $rule = NotificationRule::query()->where('name', 'বড় অঙ্ক হিসাবপ্রধানকে')->firstOrFail();
        $this->assertSame('approval', $rule->module);
        $this->assertCount(1, $rule->conditions, 'ফাঁকা শর্তের সারি বাদ পড়ে');

        $this->put(route('notification.rules.update', $rule), [
            'name' => 'বড় অঙ্ক হিসাবপ্রধানকে', 'event' => 'approval.rejected', 'is_active' => '1',
            'conditions' => [['field' => 'amount', 'op' => 'gte', 'value' => '200000']],
            'recipients' => ['users' => [$this->cfo->id]],
        ])->assertRedirect();
        $this->assertSame(2, (int) $rule->fresh()->version);
        $this->assertSame(2, NotificationRuleVersion::query()->where('rule_id', $rule->id)->count());
        $this->assertSame('200000', NotificationRuleVersion::query()->where('rule_id', $rule->id)->where('version', 2)->firstOrFail()->snapshot['conditions'][0]['value']);
        $this->assertSame(2, NotificationAuditLog::query()->where('action', 'rule_save')->count());

        // ⓘ অন্য কোম্পানির মানুষ বা অচেনা চলক নিয়মে বসে না
        $stranger = User::factory()->create(['is_active' => true]);
        $this->put(route('notification.rules.update', $rule), [
            'name' => 'x', 'event' => 'approval.rejected', 'recipients' => ['users' => [$stranger->id]],
        ])->assertSessionHasErrors('recipients.users.0');
        $this->put(route('notification.rules.update', $rule), [
            'name' => 'x', 'event' => 'approval.rejected', 'conditions' => [['field' => 'password', 'op' => 'eq', 'value' => '1']],
        ])->assertSessionHasErrors('conditions.0.field');

        $before = Notification::query()->count();
        $this->post(route('notification.rules.test', $rule), ['values' => ['amount' => '250000']])->assertOk()
            ->assertSee('data-rule-test="match"', false)->assertSee('data-would-reach="'.$this->cfo->id.'"', false);
        $this->post(route('notification.rules.test', $rule), ['values' => ['amount' => '1000']])->assertOk()
            ->assertSee('data-rule-test="no-match"', false);
        $this->assertSame($before, Notification::query()->count(), '⛔ শুকনো পরীক্ষা খবর পাঠিয়ে দিল');
    }

    private function rule(array $values): NotificationRule
    {
        return NotificationRule::query()->create($values + [
            'name' => 'পরীক্ষার নিয়ম', 'module' => 'approval', 'event' => 'approval.rejected', 'is_active' => true, 'version' => 1,
        ]);
    }

    private function got(User $user, string $title): bool
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('title', $title)->exists();
    }

    private function member(string $name, string $locale = 'bn'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'current_company_id' => $this->company->id, 'locale' => $locale]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function limit(User $user, string $code): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch($code)->id,
        ]);
        app(DataScope::class)->forget();
    }

    /** কাগজের মতো একটা কিছু — শাখা আর বানানেওয়ালা নিয়ে (খবরের শাখা এটাই ঠিক করে) */
    private function paper(string $code): Model
    {
        $paper = new class extends Model
        {
            protected $guarded = [];
        };

        return $paper->forceFill(['id' => 99, 'branch_id' => $this->branch($code)->id, 'created_by' => $this->owner->id]);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
