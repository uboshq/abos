<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Mail;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Services\FakeMailServer;
use App\Modules\Mail\Services\MailServer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মেইল ABOS-এর ভিতরে (মালিক, ২৫–২৬ নভেম্বর ২০২৬, নমুনা v3 "ok") — প্রথম ধাপের দাবি:
 *
 *   · নিজের মেইলবক্স কেবল তার — ⛔ অন্য কর্মী তো নয়ই, মালিকও (super admin) তালিকা/পড়া/পাঠানো কিছুই খুলতে পারেন না (403);
 *   · ব্যক্তিগত মেইল অনুমোদনের আগে তালিকায় দেখায় কিন্তু খোলে না; অনুমোদনকারী হ্যাঁ বললে খোলে;
 *     ⛔ নিজের অনুরোধ নিজে অনুমোদন নয় (মালিক ছাড়া);
 *   · দলের মেইলবক্স — সদস্য পড়েন; `can_send` না থাকলে পাঠাতে পারেন না; সদস্য নন এমন কেউ খুলতে পারেন না;
 *   · পড়ার সময় বাইরের ছবি বন্ধ (ট্র্যাকিং পিক্সেল থামে), স্ক্রিপ্ট সরে; "ছবি দেখান" চাইলে ছবি খোলে;
 *   · সংযোগ না হলে মেইলবক্স জমা হয় না; পাসওয়ার্ড টেবিলে খোলা লেখায় থাকে না;
 *   · মেইলের পাতা `mail.use` ছাড়া খোলে না।
 */
final class NobodyOpensAnotherPersonsMailTest extends TestCase
{
    use RefreshDatabase;

    private Company $co;

    private FakeMailServer $fake;

    private User $clerk;

    private User $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->co = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->fake = new FakeMailServer;
        $this->app->instance(MailServer::class, $this->fake);

        $this->clerk = $this->person('Mail Clerk', ['mail.use']);
        $this->other = $this->person('Other Clerk', ['mail.use', 'mail.manage']);
        $this->owner = $this->person('The Owner', ['mail.use', 'mail.manage', 'mail.personal.approve'], true);
    }

    public function test_an_own_mailbox_opens_only_for_its_holder_not_even_for_the_owner(): void
    {
        $box = $this->box($this->clerk, MailAccount::OWN, 'clerk@adi.test');

        $this->as($this->clerk);
        $this->getJson('/mail/accounts')->assertOk()->assertJsonPath('accounts.0.id', $box->public_id);
        $this->getJson("/mail/{$box->public_id}/list?folder=INBOX")->assertOk()->assertJsonPath('total', 2);

        foreach ([$this->other, $this->owner] as $who) {
            $this->as($who);
            $ids = collect($this->getJson('/mail/accounts')->assertOk()->json('accounts'))->pluck('id');
            $this->assertNotContains($box->public_id, $ids, $who->name.' sees another person\'s mailbox in the list');
            $this->getJson("/mail/{$box->public_id}/folders")->assertForbidden();
            $this->getJson("/mail/{$box->public_id}/list?folder=INBOX")->assertForbidden();
            $this->getJson("/mail/{$box->public_id}/read?folder=INBOX&uid=3")->assertForbidden();
            $this->get("/mail/{$box->public_id}/file?folder=INBOX&uid=3&part=2")->assertForbidden();
            $this->postJson("/mail/{$box->public_id}/send", ['to' => ['x@example.com'], 'subject' => 's', 'html' => '<p>x</p>'])->assertForbidden();
        }

        $this->assertSame([], $this->fake->sent);
    }

    public function test_personal_mail_waits_for_approval_and_nobody_approves_their_own(): void
    {
        $this->as($this->clerk);
        $this->postJson('/mail/accounts', ['kind' => 'personal', 'provider' => 'gmail', 'email' => 'clerk.home@gmail.com',
            'secret' => 'app-pass-1234', 'reason' => 'bank sends statements here', 'months' => 3])
            ->assertOk()->assertJsonPath('message', __('mail::message.requested'));

        $box = MailAccount::query()->where('email', 'clerk.home@gmail.com')->firstOrFail();
        $this->assertSame(MailAccount::PENDING, $box->status);
        $this->assertSame(now()->addMonths(3)->toDateString(), $box->expires_on->toDateString());

        $this->getJson('/mail/accounts')->assertOk()->assertJsonPath('accounts.0.usable', false);
        $this->getJson("/mail/{$box->public_id}/list?folder=INBOX")->assertForbidden();
        $this->postJson("/mail/accounts/{$box->public_id}/decide", ['yes' => true])->assertForbidden();

        $this->as($this->owner);
        $this->getJson('/mail/accounts')->assertOk()->assertJsonPath('requests.0.id', $box->public_id);
        $this->postJson("/mail/accounts/{$box->public_id}/decide", ['yes' => true])->assertOk();
        // ⛔ অনুমোদন দিলেও মালিক নিজে সেই মেইল খুলতে পারেন না
        $this->getJson("/mail/{$box->public_id}/list?folder=INBOX")->assertForbidden();

        $this->as($this->clerk);
        $this->getJson("/mail/{$box->public_id}/list?folder=INBOX")->assertOk();
    }

    public function test_a_clerk_with_the_approve_key_still_cannot_approve_their_own_request(): void
    {
        $approver = $this->person('Approver Clerk', ['mail.use', 'mail.personal.approve']);
        $this->as($approver);
        $this->postJson('/mail/accounts', ['kind' => 'personal', 'provider' => 'gmail', 'email' => 'ap@gmail.com',
            'secret' => 'app-pass-9', 'reason' => 'x'])->assertOk();
        $box = MailAccount::query()->where('email', 'ap@gmail.com')->firstOrFail();

        $this->postJson("/mail/accounts/{$box->public_id}/decide", ['yes' => true])->assertForbidden();
        $this->assertSame(MailAccount::PENDING, $box->fresh()->status);
    }

    public function test_a_team_mailbox_opens_for_members_and_sends_only_with_the_send_right(): void
    {
        $box = $this->box(null, MailAccount::SHARED, 'sales@adi.test');
        $box->members()->sync([$this->clerk->id => ['can_send' => false], $this->other->id => ['can_send' => true]]);

        $this->as($this->clerk);
        $this->getJson("/mail/{$box->public_id}/read?folder=INBOX&uid=2")->assertOk();
        $this->postJson("/mail/{$box->public_id}/send", ['to' => ['x@example.com'], 'subject' => 's', 'html' => '<p>x</p>'])->assertForbidden();

        $this->as($this->other);
        $this->postJson("/mail/{$box->public_id}/send", ['to' => ['x@example.com'], 'subject' => 'দর', 'html' => '<p>ok</p><script>alert(1)</script>'])->assertOk();
        $this->assertCount(1, $this->fake->sent);
        $this->assertSame('sales@adi.test', $this->fake->sent[0]['from']);
        $this->assertStringNotContainsString('<script', $this->fake->sent[0]['html']);

        $this->as($this->owner);
        $this->getJson("/mail/{$box->public_id}/read?folder=INBOX&uid=2")->assertForbidden();
    }

    public function test_reading_holds_back_outside_images_until_asked(): void
    {
        $box = $this->box($this->clerk, MailAccount::OWN, 'clerk@adi.test');
        $this->fake->boxes['INBOX'][0]['html'] .= '<script>steal()</script><a href="javascript:steal()" onclick="x()">x</a>';

        $this->as($this->clerk);
        $m = $this->getJson("/mail/{$box->public_id}/read?folder=INBOX&uid=3")->assertOk()->json();
        $this->assertStringContainsString('data-blocked-src="https://tracker.example/p.gif"', $m['body']);
        $this->assertStringNotContainsString('src="https://tracker', $m['body']);
        $this->assertStringNotContainsString('<script', $m['body']);
        $this->assertStringNotContainsString('javascript:', $m['body']);
        $this->assertStringNotContainsString('onclick', $m['body']);
        $this->assertTrue($m['external'], 'a mail from outside the box\'s domain carries the outside warning');

        $shown = $this->getJson("/mail/{$box->public_id}/read?folder=INBOX&uid=3&images=1")->assertOk()->json('body');
        $this->assertStringContainsString('src="https://tracker.example/p.gif"', $shown);
    }

    public function test_a_box_that_cannot_connect_is_not_saved_and_the_password_is_never_plain(): void
    {
        $this->as($this->clerk);
        $this->fake->fail = true;
        $this->postJson('/mail/accounts', ['kind' => 'own', 'provider' => 'company', 'email' => 'bad@adi.test', 'secret' => 'wrong-pass'])
            ->assertStatus(422)->assertJsonValidationErrors('secret');
        $this->assertFalse(MailAccount::query()->where('email', 'bad@adi.test')->exists());

        $this->fake->fail = false;
        $this->postJson('/mail/accounts', ['kind' => 'own', 'provider' => 'company', 'email' => 'good@adi.test', 'secret' => 'Plain-Secret-77'])->assertOk();
        $raw = (string) DB::table('mail_accounts')->where('email', 'good@adi.test')->value('secret');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('Plain-Secret-77', $raw);
        $this->assertStringNotContainsString('Plain-Secret-77', $this->getJson('/mail/accounts')->getContent());

        // ⛔ দলের মেইলবক্স কেবল `mail.manage`
        $this->postJson('/mail/accounts', ['kind' => 'shared', 'provider' => 'company', 'email' => 'team@adi.test', 'secret' => 'x'])->assertForbidden();
    }

    public function test_the_mail_page_needs_the_mail_key(): void
    {
        $this->as($this->clerk);
        $this->get('/mail')->assertOk()->assertSee('data-mailx', false);

        $none = $this->person('No Mail', []);
        $this->as($none);
        $this->get('/mail')->assertForbidden();
        $this->getJson('/mail/accounts')->assertForbidden();
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────

    private function person(string $name, array $keys, bool $superAdmin = false): User
    {
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $this->co->id,
            'current_branch_id' => $this->co->defaultBranch()?->id, 'is_active' => true]);
        $user->companies()->attach($this->co->id, ['is_active' => true]);

        CompanyContext::forCompany($this->co->id, function () use ($user, $keys, $superAdmin) {
            foreach ($keys as $k) {
                $user->givePermissionTo(Permission::findOrCreate($k, 'web'));
            }
            if ($superAdmin) {
                $user->assignRole(Role::findOrCreate(PermissionSyncer::SUPER_ADMIN_ROLE, 'web'));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function box(?User $holder, string $kind, string $email): MailAccount
    {
        return CompanyContext::forCompany($this->co->id, fn () => MailAccount::query()->create([
            'user_id' => $holder?->id, 'kind' => $kind, 'provider' => 'company', 'email' => $email,
            'imap_host' => 'mail.adi.test', 'imap_port' => 993, 'imap_secure' => 'ssl',
            'smtp_host' => 'mail.adi.test', 'smtp_port' => 465, 'smtp_secure' => 'ssl',
            'secret' => 'x', 'status' => MailAccount::ACTIVE,
        ]));
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $fresh = $user->fresh();
        CompanyContext::set((int) $fresh->current_company_id, $fresh->current_branch_id);
        $this->actingAs($fresh);
    }
}
