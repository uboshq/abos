<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Mail;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Models\MailAddress;
use App\Modules\Mail\Models\MailFestival;
use App\Modules\Mail\Models\MailLink;
use App\Modules\Mail\Models\MailSend;
use App\Modules\Mail\Models\MailTemplate;
use App\Modules\Mail\Services\FakeMailHost;
use App\Modules\Mail\Services\FakeMailServer;
use App\Modules\Mail\Services\MailAddresses;
use App\Modules\Mail\Services\MailHost;
use App\Modules\Mail\Services\MailServer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মেইল — নমুনা v4 (মালিকের "ok", ২৬ নভেম্বর ২০২৬) আর অডিটের নির্দেশ ("ok"):
 *
 *   · প্রতিটা পাঠানো খাতায় (কে, কোন ঠিকানা থেকে, কাকে, বিষয়, টেমপ্লেট) আর মেইলবক্সের অডিটে; সই নিজে বসে;
 *   · প্রথমবার খুললে 19টা উৎসব আর কাজের টেমপ্লেট বসে; লেখার পর্দায় টেমপ্লেট প্রাপকের আসল তথ্যে;
 *   · একসাথে অনেক — পড়া, পরে দেখব, মোছা; দলের মেইলবক্সে মোছা খাতায় ওঠে;
 *   · "এই প্রেরকের ছবি সবসময়" — পরের বার ছবি খোলা; "গ্রাহকের পাতায় রাখুন" — বাঁধন থাকে;
 *   · তালিকার চিহ্ন — গ্রাহক আর তার বাকি; ⛔ চাবি ছাড়া চিহ্ন নেই;
 *   · প্রশাসন: নতুন ঠিকানা মেইলবক্সসহ; ⛔ সিস্টেমের ঠিকানা বন্ধ/মোছা নয়; ⛔ চালু ঠিকানা সরাসরি মোছা নয়; ⛔ ভুল ঠিকানা বা পাসওয়ার্ডে নয়;
 *     মোছা = 30 দিন রাখা, ফেরানো যায়; দিন পেরোলে সার্ভার থেকে মোছে; ⛔ মালিক বা চাবি ছাড়া মোছা নয়; প্রতিটা বদল অডিটে;
 *   · নিজে থেকে পাঠানো: চালু করলে উৎসবের দিনে যায়, ⛔ একদিনে দুবার নয়;
 *   · সব পাতা খোলে।
 */
final class TheMailWorksAsTheOwnerApprovedTest extends TestCase
{
    use RefreshDatabase;

    private Company $co;

    private FakeMailServer $fake;

    private FakeMailHost $host;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->co = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->fake = new FakeMailServer;
        $this->host = new FakeMailHost;
        $this->app->instance(MailServer::class, $this->fake);
        $this->app->instance(MailHost::class, $this->host);

        $this->owner = $this->person('Mail Owner', ['mail.use', 'mail.manage', 'mail.personal.approve', 'customer.view', 'supplier.view'], true);
        $this->clerk = $this->person('Mail Clerk', ['mail.use', 'customer.view']);
    }

    public function test_a_send_is_logged_audited_and_carries_the_signature_and_template(): void
    {
        $box = $this->box($this->clerk, MailAccount::OWN, 'clerk@adi.test');
        $this->as($this->clerk);
        $this->get('/mail')->assertOk();
        $tpl = MailTemplate::query()->whereNull('user_id')->where('key', 'due_reminder')->firstOrFail();

        $this->postJson("/mail/{$box->public_id}/send", ['to' => ['karim@example.com'], 'subject' => 'বাকি', 'html' => '<p>দয়া করে দেখুন</p>',
            'template' => $tpl->public_id, 'signature' => 'new'])->assertOk();

        $this->assertCount(1, $this->fake->sent);
        $this->assertStringContainsString('abos-signature', $this->fake->sent[0]['html'], '⛔ সই বসেনি।');
        $this->assertSame('Mail Clerk', $this->fake->sent[0]['fromName']);
        // ⛔ কোম্পানির লোগো নেই → সইয়ে ভাঙা ছবি নয় (ডেমোতে "Demo Company" লেখা ভাঙা ছবি দেখা গিয়েছিল, ২৭ নভেম্বর)
        // ⓘ পরীক্ষার কোম্পানিতে লোগোর পথ লেখা আছে, ফাইলটা নেই — ডেমোর মতোই অবস্থা
        $this->assertNull(app(\App\Modules\Mail\Services\MailSignatures::class)->logoBytes(), 'পরীক্ষার কোম্পানির লোগোর ফাইল থাকার কথা নয়।');
        $this->assertStringNotContainsString('src=""', $this->fake->sent[0]['html'], '⛔ লোগো ছাড়া সইয়ে ফাঁকা ছবি বসেছে।');
        $this->assertStringNotContainsString('{{SIG_', $this->fake->sent[0]['html'], '⛔ সইয়ের চিহ্ন বদলায়নি।');

        $row = MailSend::query()->sole();
        $this->assertSame('clerk@adi.test', $row->from_email);
        $this->assertSame('karim@example.com', $row->to);
        $this->assertSame($tpl->id, $row->template_id);
        $this->assertSame($this->clerk->id, $row->sent_by);
        $this->assertSame(1, $tpl->fresh()->used);
        $this->assertTrue(AuditTrail::query()->where('auditable_type', MailAccount::class)->where('auditable_id', $box->id)->where('action', 'mail_sent')->exists(),
            '⛔ পাঠানো মেইলবক্সের অডিটে নেই।');
    }

    public function test_the_first_open_seeds_festivals_and_templates_and_compose_fills_the_recipient(): void
    {
        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['email' => 'karim@example.com'])->save();
        $this->as($this->clerk);
        $this->get('/mail')->assertOk();

        $this->assertSame(19, MailFestival::query()->count());
        $this->assertTrue(MailFestival::query()->where('key', 'victory')->value('quiet'), '⛔ বিজয় দিবস শ্রদ্ধার দিন — অফার নয়।');
        $this->assertGreaterThanOrEqual(13 + 19, MailTemplate::query()->count());

        $j = $this->getJson('/mail/compose?to=karim@example.com')->assertOk()->json();
        $due = collect($j['templates'])->firstWhere('name', 'বকেয়ার বিনীত স্মরণ');
        $this->assertNotNull($due);
        $this->assertStringContainsString(e($customer->name()), $due['html'], '⛔ ঘরে গ্রাহকের নাম বসেনি।');
        $this->assertStringNotContainsString('{গ্রাহকের নাম}', $due['html']);
        $this->assertNotEmpty($j['signature']['new']);
    }

    public function test_bulk_flags_and_shared_deletes_are_logged(): void
    {
        $box = $this->box(null, MailAccount::SHARED, 'sales@adi.test');
        $box->members()->sync([$this->clerk->id => ['can_send' => true]]);
        $this->fake->boxes['Trash'] = [];
        $this->as($this->clerk);

        $this->postJson("/mail/{$box->public_id}/bulk", ['folder' => 'INBOX', 'uids' => [3, 2], 'do' => 'read'])->assertOk();
        $this->assertTrue(collect($this->fake->boxes['INBOX'])->every(fn ($m) => $m['seen']));

        $this->postJson("/mail/{$box->public_id}/bulk", ['folder' => 'INBOX', 'uids' => [2], 'do' => 'later'])->assertOk();
        $this->assertTrue((bool) collect($this->fake->boxes['INBOX'])->firstWhere('uid', 2)['later']);

        $this->postJson("/mail/{$box->public_id}/move", ['folder' => 'INBOX', 'uid' => 3, 'to' => 'Trash', 'subject' => 'বিল S-0014-এর টাকা', 'action' => 'mail_deleted'])->assertOk();
        $line = AuditTrail::query()->where('auditable_id', $box->id)->where('auditable_type', MailAccount::class)->where('action', 'mail_deleted')->first();
        $this->assertNotNull($line, '⛔ দলের মেইলবক্সে মোছা খাতায় ওঠেনি।');
        $this->assertStringContainsString('S-0014', (string) $line->reason);
    }

    public function test_a_trusted_sender_shows_images_and_a_mail_can_be_kept_on_the_party_page(): void
    {
        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['email' => 'karim@example.com'])->save();
        $box = $this->box($this->clerk, MailAccount::OWN, 'clerk@adi.test');
        $this->as($this->clerk);

        $this->assertStringContainsString('data-blocked-src', $this->getJson("/mail/{$box->public_id}/read?folder=INBOX&uid=3")->json('body'));
        $this->postJson('/mail/trust', ['email' => 'karim@example.com', 'on' => true])->assertOk();
        $m = $this->getJson("/mail/{$box->public_id}/read?folder=INBOX&uid=3")->assertOk()->json();
        $this->assertTrue($m['trusted']);
        $this->assertStringNotContainsString('data-blocked-src', $m['body']);

        $this->postJson("/mail/{$box->public_id}/link", ['messageId' => '<m3@test>', 'subject' => 'বিল', 'from' => 'karim@example.com', 'type' => 'customer', 'id' => $customer->id])->assertOk();
        $this->assertTrue(MailLink::query()->where('message_id', '<m3@test>')->where('linkable_id', $customer->id)->exists());
        $this->assertTrue($this->getJson("/mail/{$box->public_id}/read?folder=INBOX&uid=3")->json('linked'));

        $tags = $this->postJson('/mail/parties', ['emails' => ['karim@example.com', 'pran@example.com']])->assertOk()->json('parties');
        $this->assertSame('customer', $tags['karim@example.com']['kind']);

        $nobody = $this->person('No Customer Key', ['mail.use']);
        $this->as($nobody);
        $this->assertSame([], (array) $this->postJson('/mail/parties', ['emails' => ['karim@example.com']])->json('parties'), '⛔ চাবি ছাড়া গ্রাহকের চিহ্ন।');
    }

    public function test_the_admin_creates_pauses_deletes_after_thirty_days_and_never_touches_a_system_address(): void
    {
        // ⓘ পাসওয়ার্ড আগে — বদলালে ABOS পুরনো সেশন বন্ধ করে (নিরাপত্তা), তাই ঢোকার আগেই বসানো
        $this->owner->forceFill(['password' => Hash::make('owner-pass-1')])->save();
        $this->as($this->owner);
        $this->post('/mail/addresses', ['kind' => 'person', 'user_id' => $this->clerk->id, 'local' => 'najma', 'domain' => 'adi.test',
            'display_name' => 'Najma', 'quota_mb' => 5120, 'deliver' => 'screen'])->assertRedirect()->assertSessionHas('mail_password');

        $address = MailAddress::query()->where('email', 'najma@adi.test')->firstOrFail();
        $this->assertSame(1, $this->host->count('create'));
        $this->assertSame($this->clerk->id, $address->account->user_id, '⛔ কর্মীর মেইলের পর্দায় নিজে যোগ হয়নি।');
        $this->assertSame(MailAccount::OWN, $address->account->kind);
        $this->assertTrue(AuditTrail::query()->where('auditable_type', MailAddress::class)->where('auditable_id', $address->id)->where('action', 'created')->exists());

        // ⛔ চালু ঠিকানা সরাসরি মোছা নয়
        $this->post("/mail/addresses/{$address->public_id}/delete", ['typed' => 'najma@adi.test', 'password' => 'password'])->assertSessionHasErrors('email');

        $this->post("/mail/addresses/{$address->public_id}/pause", ['mode' => 'bounce', 'reason' => 'ছুটি'])->assertRedirect();
        $this->assertSame(MailAddress::PAUSED, $address->fresh()->status);
        $this->assertSame(MailAccount::OFF, $address->fresh()->account->status);

        // ⛔ ভুল ঠিকানা বা ভুল পাসওয়ার্ডে মোছা নয়
        $this->post("/mail/addresses/{$address->public_id}/delete", ['typed' => 'wrong@adi.test', 'password' => 'owner-pass-1'])->assertSessionHasErrors('typed');
        $this->post("/mail/addresses/{$address->public_id}/delete", ['typed' => 'najma@adi.test', 'password' => 'nope'])->assertSessionHasErrors('password');

        $this->post("/mail/addresses/{$address->public_id}/delete", ['typed' => 'najma@adi.test', 'password' => 'owner-pass-1'])->assertRedirect();
        $this->assertSame(MailAddress::DELETING, $address->fresh()->status);
        $this->assertSame(now()->addDays(30)->toDateString(), $address->fresh()->delete_on->toDateString());
        $this->assertSame(0, $this->host->count('delete'), '⛔ 30 দিনের আগেই সার্ভার থেকে মুছে গেল।');

        $this->post("/mail/addresses/{$address->public_id}/restore")->assertRedirect();
        $this->assertSame(MailAddress::PAUSED, $address->fresh()->status);

        $this->post("/mail/addresses/{$address->public_id}/delete", ['typed' => 'najma@adi.test', 'password' => 'owner-pass-1'])->assertRedirect();
        $this->travel(31)->days();
        $backups = 0;
        app(MailAddresses::class)->tidy(function () use (&$backups) { $backups++; });
        $this->assertSame(MailAddress::DELETED, $address->fresh()->status);
        $this->assertSame(1, $this->host->count('delete'));
        $this->assertSame(1, $backups, '⛔ মোছার আগে ব্যাকআপ হয়নি।');
    }

    public function test_a_system_address_is_never_paused_or_deleted_and_only_the_owner_deletes(): void
    {
        $this->host->boxes['no-reply@adi.test'] = ['email' => 'no-reply@adi.test', 'used_mb' => 1.0, 'quota_mb' => 2048, 'login_off' => false, 'incoming_off' => false];
        $this->host->boxes['rafiq@adi.test'] = ['email' => 'rafiq@adi.test', 'used_mb' => 1.0, 'quota_mb' => 2048, 'login_off' => false, 'incoming_off' => false];
        $this->as($this->owner);
        $this->get('/mail/addresses')->assertOk()->assertSee('no-reply@adi.test');

        $system = MailAddress::query()->where('email', 'no-reply@adi.test')->firstOrFail();
        $this->assertSame(MailAddress::SYSTEM, $system->kind, '⛔ সার্ভারের no-reply@ সিস্টেম বলে চেনা হয়নি।');
        $this->post("/mail/addresses/{$system->public_id}/pause", ['mode' => 'bounce'])->assertStatus(422);
        $this->assertSame(0, $this->host->count('login'));

        $rafiq = MailAddress::query()->where('email', 'rafiq@adi.test')->firstOrFail();
        $this->post("/mail/addresses/{$rafiq->public_id}/pause", ['mode' => 'bounce'])->assertRedirect();

        $manager = $this->person('Mail Manager', ['mail.use', 'mail.manage']);
        $manager->forceFill(['password' => Hash::make('m-pass-1')])->save();
        $this->as($manager);
        $this->post("/mail/addresses/{$rafiq->public_id}/delete", ['typed' => 'rafiq@adi.test', 'password' => 'm-pass-1'])->assertForbidden();

        $this->as($this->clerk);
        $this->get('/mail/addresses')->assertForbidden();
    }

    public function test_automatic_festival_mail_goes_once_on_the_day_only_when_switched_on(): void
    {
        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['email' => 'karim@example.com', 'is_active' => true])->save();
        $box = $this->box(null, MailAccount::SHARED, 'info@adi.test');
        $this->as($this->clerk);
        $this->get('/mail')->assertOk();

        CompanyContext::forCompany($this->co->id, function () use ($box) {
            app(SettingsService::class)->set('mail.auto_from', $box->public_id);
            MailFestival::query()->where('key', 'victory')->update(['on_date' => now()->toDateString()]);
        });
        $tpl = MailTemplate::query()->where('key', 'fest_victory')->firstOrFail();
        $this->travelTo(now()->setTime(9, 5));

        $this->artisan('abos:mail-auto')->assertSuccessful();
        $this->assertSame([], $this->fake->sent, '⛔ চালু না করেই নিজে থেকে মেইল গেল।');

        $tpl->forceFill(['auto' => ['on' => true, 'rule' => 'festival', 'at' => '09:00']])->save();
        $this->artisan('abos:mail-auto')->assertSuccessful();
        $this->artisan('abos:mail-auto')->assertSuccessful();

        $mine = array_values(array_filter($this->fake->sent, fn ($m) => $m['to'] === ['karim@example.com']));
        $this->assertCount(1, $mine, '⛔ একই গ্রাহক একদিনে দুবার পেল, নয়তো পায়নি।');
        $this->assertStringContainsString('বিজয়', $mine[0]['html']);
        $this->assertTrue(MailSend::query()->where('auto', true)->where('template_id', $tpl->id)->exists());
    }

    public function test_every_mail_page_opens(): void
    {
        $this->as($this->owner);

        foreach (['/mail', '/mail/signature', '/mail/templates', '/mail/templates?cat=festival', '/mail/addresses', '/mail/history', '/mail/sent'] as $page) {
            // ⛔ পাশের রেল আর মেইলের মেনু — আগে কোনো মেইল পাতা মেনু দিত না, পাশটা খালি দেখাত (ডেমো, ২৭ নভেম্বর)
            $this->get($page)->assertOk()->assertSee('data-active-module="mail"', false);
        }

        // ⓘ নতুন সারিতে অডিট কেবল "তৈরি" লেখে; বদলে আগে → পরে আসে — তাই দুবার
        $this->post('/mail/signature', ['style_new' => 12, 'style_reply' => 10, 'festival' => 1, 'title' => 'Sales'])->assertRedirect();
        $this->post('/mail/signature', ['style_new' => 12, 'style_reply' => 10, 'festival' => 1, 'title' => 'Head of Sales'])->assertRedirect();
        $this->get('/mail/history?type=signature')->assertOk()->assertSee('Head of Sales');
        $this->getJson('/mail/compose?paper=nonsense&no=1')->assertStatus(422);
    }

    public function test_signature_eight_shows_the_bengali_and_english_name_and_title_side_by_side(): void
    {
        $this->clerk->forceFill(['name_bn' => 'মেইল কেরানি', 'designation' => 'Sales Officer', 'designation_bn' => 'বিক্রয় কর্মকর্তা'])->save();
        $this->as($this->clerk);

        $html = app(\App\Modules\Mail\Services\MailSignatures::class)->preview($this->clerk->fresh(), 8);

        foreach (['মেইল কেরানি', 'বিক্রয় কর্মকর্তা', 'Mail Clerk', 'Sales Officer'] as $word) {
            $this->assertStringContainsString($word, $html, "⛔ নকশা 8-এ «{$word}» নেই।");
        }

        // ⛔ আগে ডান পাশে পদের বদলে "Designation" শব্দটাই বসত (ডেমো, ২৭ নভেম্বর)
        $this->assertStringNotContainsString('Designation', $html);
    }

    public function test_the_bar_shows_mail_with_its_unread_count_only_in_abos_looks(): void
    {
        $this->box($this->clerk, MailAccount::OWN, 'clerk@adi.test');

        $this->clerk->forceFill(['ui' => 'rose'])->save();
        $this->as($this->clerk);
        $this->get('/mail/signature')->assertOk()->assertSee('data-mail-topbar', false);
        $this->assertSame(1, $this->getJson('/mail/unread')->assertOk()->json('unread'));

        $this->clerk->forceFill(['ui' => 'dynamic'])->save();
        $this->as($this->clerk);
        $this->get('/mail/signature')->assertOk()->assertDontSee('data-mail-topbar', false);

        // ⛔ মেইলবক্স না থাকলে চিহ্নও নেই
        $none = $this->person('No Box', ['mail.use']);
        $none->forceFill(['ui' => 'rose'])->save();
        $this->as($none);
        $this->get('/mail/signature')->assertOk()->assertDontSee('data-mail-topbar', false);
    }

    public function test_send_later_waits_then_goes_once_and_leaves_no_text_behind(): void
    {
        $box = $this->box($this->clerk, MailAccount::OWN, 'clerk@adi.test');
        $this->as($this->clerk);

        // ⛔ নিজের ফাইল নিয়ে পরে পাঠানো নয় — সময় এগোনোর আগে (এক ঘণ্টা চুপ থাকলে ABOS নিজেই সেশন বন্ধ করে)
        $this->post("/mail/{$box->public_id}/send", ['to' => ['karim@example.com'], 'subject' => 'f', 'html' => '<p>x</p>',
            'send_at' => now()->addHour()->format('Y-m-d H:i:s'), 'files' => [\Illuminate\Http\UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]],
            ['Accept' => 'application/json'])->assertStatus(422);

        $this->postJson("/mail/{$box->public_id}/send", ['to' => ['karim@example.com'], 'subject' => 'পরে', 'html' => '<p>x</p>',
            'send_at' => now()->addHour()->format('Y-m-d H:i:s')])->assertOk();
        $this->assertSame([], $this->fake->sent);
        $row = \App\Modules\Mail\Models\MailOutbox::query()->sole();
        $this->assertStringNotContainsString('পরে', (string) \Illuminate\Support\Facades\DB::table('mail_outbox')->value('payload'), '⛔ অপেক্ষার লেখা খোলা অবস্থায় জমা।');

        $this->artisan('abos:mail-outbox')->assertSuccessful();
        $this->assertSame([], $this->fake->sent, '⛔ সময়ের আগে গেল।');

        $this->travel(61)->minutes();
        $this->artisan('abos:mail-outbox')->assertSuccessful();
        $this->artisan('abos:mail-outbox')->assertSuccessful();
        $this->assertCount(1, $this->fake->sent);
        $this->assertFalse(\App\Modules\Mail\Models\MailOutbox::query()->whereKey($row->id)->exists(), '⛔ পাঠানোর পরেও লেখা রয়ে গেল।');
        $this->assertSame(1, MailSend::query()->count());

    }

    public function test_source_auto_reply_import_html_template_and_own_signature(): void
    {
        $box = $this->box($this->clerk, MailAccount::OWN, 'clerk@adi.test');
        $this->as($this->clerk);
        $this->get("/mail/{$box->public_id}/raw?folder=INBOX&uid=3")->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('Message-ID');

        $this->post('/mail/signature', ['style_new' => 21, 'style_reply' => 10, 'custom' => "Mail Clerk\n<script>x()</script><b>ADI</b>"])->assertRedirect();
        $this->postJson("/mail/{$box->public_id}/send", ['to' => ['a@example.com'], 'subject' => 's', 'html' => '<p>x</p>'])->assertOk();
        $this->assertStringContainsString('<b>ADI</b>', $this->fake->sent[0]['html']);
        $this->assertStringNotContainsString('<script', $this->fake->sent[0]['html']);

        $this->as($this->owner);
        $this->post('/mail/addresses', ['kind' => 'person', 'user_id' => $this->clerk->id, 'local' => 'away', 'domain' => 'adi.test', 'quota_mb' => 2048, 'deliver' => 'screen'])->assertRedirect();
        $a = MailAddress::query()->where('email', 'away@adi.test')->firstOrFail();
        $this->post("/mail/addresses/{$a->public_id}/auto-reply", ['on' => 1, 'subject' => 'ছুটিতে', 'body' => 'ফিরে উত্তর দেব', 'start' => now()->toDateString()])->assertRedirect();
        $this->assertSame(1, $this->host->count('autoReply'));
        $this->assertSame('ছুটিতে', $a->fresh()->auto_reply['subject']);

        $csv = "address,kind,staff,gb\nbulk1@adi.test,person,{$this->clerk->email},2\nnobody@adi.test,person,missing@x.test,2\n";
        $this->post('/mail/addresses/import', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('a.csv', $csv)])->assertRedirect();
        $this->assertTrue(MailAddress::query()->where('email', 'bulk1@adi.test')->exists());
        $this->assertFalse(MailAddress::query()->where('email', 'nobody@adi.test')->exists(), '⛔ অচেনা কর্মীর ঠিকানা তৈরি হলো।');

        $this->post('/mail/templates', ['name' => 'নিজের নকশা', 'category' => 'offer', 'locale' => 'bn', 'format' => 'html', 'subject' => 'অফার',
            'html_file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('t.html', '<h1>{গ্রাহকের নাম}</h1><script>bad()</script>')])->assertRedirect();
        $t = MailTemplate::query()->where('name', 'নিজের নকশা')->firstOrFail();
        $this->assertSame('html', $t->format);
        $this->assertStringNotContainsString('<script', $t->body);
        $this->get('/mail/templates?look=bn')->assertOk();
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
