<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\PartyMessageDispatcher;
use App\Core\Services\SettingsService;
use App\Core\Services\WhatsAppCloud;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\SmsGatewayPhone;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ⭐ বার্তার পথ আর Meta Cloud API (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব ঘ)।
 *
 * ⭐ দাবি:
 *   · পাতা কেবল `notification.party_roads` চাবিতে; তিন কার্ড, "কোন পথ আগে", শুরুর ভাষা
 *   · তিন সুইচ আর শুরুর ভাষা সংরক্ষণ হয়; Cloud-এর সারি `party_whatsapp`, verify token নিজেই বসে
 *   · চাবি (token) আর App secret ফাঁকা পাঠালে আগেরটাই থাকে, পাতায় বা ডাটাবেজের কাঁচা লেখায় কখনো নয়; নতুন চাবি মানে আবার পরীক্ষা
 *   · সংযোগ পরীক্ষা: ঠিক হলে "চালু", ভুল হলে "সংযোগে ভুল" আর Meta-র কারণ — চাবি ছাড়া
 *   · পাঠানোর ছাঁচ: অনুমোদিত লেখার নাম, ভাষা, {নাম}গুলো লেখার ক্রমে; অনুমোদন ছাড়া কিছুই যায় না
 *   · webhook: verify token আর স্বাক্ষর না মিললে ৪০৩; অন্য নম্বরের খবর ছোঁয় না
 *   · পৌঁছেছে → খাতার সারি এগোয়; Cloud ব্যর্থ → SMS-এ নতুন সারি (পেছনের সারির দিকে আঙুল)
 *   · লেখার অনুমোদনের খবর → wa_status; অনুমোদনে পাঠানো → {{n}} আর উদাহরণসহ, wa_status pending
 */
final class PartyRoadsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '109876543210';

    private const WABA_ID = '204060801234';

    private const TOKEN = 'EAAG-very-secret-token-123';

    private const APP_SECRET = 'app-secret-xyz-789';

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
        $this->owner->givePermissionTo('notification.party_roads', 'notification.party_templates');
        $this->clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($this->owner);
    }

    public function test_the_page_answers_only_to_its_key(): void
    {
        $this->get(route('notification.party_roads.index'))->assertOk()
            ->assertSee(__('notification::party_roads.title'))
            ->assertSee('data-road="wame"', false)->assertSee('data-road="cloud"', false)->assertSee('data-road="sms"', false)
            ->assertSee('data-road-order', false)
            ->assertSee(__('notification::party_roads.cloud.cost_unset'))
            ->assertSee(__('notification::party_roads.cloud.states.waiting'));
        $this->get(route('notification.party_roads.index', ['tab' => 'cost']))->assertOk()->assertSee('data-road-cost', false);

        $this->actingAs($this->clerk);
        $this->get(route('notification.party_roads.index'))->assertForbidden();
        $this->put(route('notification.party_roads.save'), ['default_locale' => 'bn'])->assertForbidden();
        $this->post(route('notification.party_roads.check'))->assertForbidden();
    }

    public function test_the_switches_and_the_language_are_saved(): void
    {
        $this->saveRoads(['sms' => '1', 'cloud' => '1', 'default_locale' => 'en', 'sender_id' => '01700-000000'])
            ->assertRedirect(route('notification.party_roads.index'));

        $settings = app(SettingsService::class);
        $this->assertFalse((bool) $settings->get(PartyMessageDispatcher::WAME_ON));
        $this->assertTrue((bool) $settings->get(PartyMessageDispatcher::SMS_ON));
        $this->assertSame('en', $settings->get(PartyMessageDispatcher::DEFAULT_LOCALE));

        $row = $this->cloudRow();
        $this->assertTrue($row->enabled);
        $this->assertSame('01700-000000', $row->sender_id);
        $this->assertSame(self::PHONE_ID, $row->secret('phone_number_id'));
        $this->assertNotNull($row->secret('verify_token'), 'the webhook needs a verify token');
        $this->assertNotContains(NotificationChannel::PARTY_WHATSAPP, NotificationChannel::ALL, '⛔ the staff channels page must not list the party road');

        $this->saveRoads(['wame' => '1', 'default_locale' => 'xx'])->assertSessionHasErrors('default_locale');
    }

    public function test_the_token_is_kept_when_blank_and_never_shown_back(): void
    {
        $this->saveRoads(['cloud' => '1', 'token' => self::TOKEN, 'app_secret' => self::APP_SECRET]);
        $verify = $this->cloudRow()->secret('verify_token');

        $this->cloudRow()->forceFill(['last_check_ok' => true])->save();
        $this->saveRoads(['cloud' => '1', 'token' => '', 'app_secret' => '']);

        $row = $this->cloudRow();
        $this->assertSame(self::TOKEN, $row->secret('token'));
        $this->assertSame(self::APP_SECRET, $row->secret('app_secret'));
        $this->assertSame($verify, $row->secret('verify_token'), 'the verify token never changes by itself');
        $this->assertTrue($row->last_check_ok, 'nothing changed, so the check still stands');

        $raw = (string) DB::table('notification_channels')->where('id', $row->id)->value('credentials');
        $this->assertStringNotContainsString(self::TOKEN, $raw, '⛔ the token sits unencrypted');
        $this->assertStringNotContainsString(self::APP_SECRET, $raw);

        $this->get(route('notification.party_roads.index'))->assertOk()
            ->assertDontSee(self::TOKEN)->assertDontSee(self::APP_SECRET)
            ->assertSee('data-has-token="1"', false);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($row->toArray()));

        $this->saveRoads(['cloud' => '1', 'token' => 'EAAG-a-new-token']);
        $this->assertNull($this->cloudRow()->last_check_ok, '⛔ a new token was trusted without a check');
    }

    public function test_the_connection_check_says_on_or_shows_metas_reason(): void
    {
        $this->saveRoads(['cloud' => '1', 'token' => self::TOKEN, 'app_secret' => self::APP_SECRET]);

        // ⓘ one fake, a sequence — a second Http::fake() would sit behind the first stub and never answer
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['id' => self::PHONE_ID, 'display_phone_number' => '+880 1700-000000'])
            ->push(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);
        $this->post(route('notification.party_roads.check'))->assertSessionHas('saved');
        $this->assertTrue($this->cloudRow()->last_check_ok);
        $this->assertTrue(app(WhatsAppCloud::class)->ready());
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), WhatsAppCloud::BASE.'/'.WhatsAppCloud::DEFAULT_VERSION.'/'.self::PHONE_ID)
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && ! str_contains($r->url(), self::TOKEN));
        $this->get(route('notification.party_roads.index'))->assertSee('data-cloud-state="on"', false);

        $this->post(route('notification.party_roads.check'))->assertSessionHas('failed');
        $row = $this->cloudRow();
        $this->assertFalse($row->last_check_ok);
        $this->assertSame('Invalid OAuth access token.', $row->last_error);
        $this->assertFalse(app(WhatsAppCloud::class)->ready());
        $this->get(route('notification.party_roads.index'))
            ->assertSee('data-cloud-state="error"', false)
            ->assertSee(__('notification::party_roads.cloud.states.error'))
            ->assertSee('Invalid OAuth access token.');
    }

    public function test_a_send_carries_the_approved_template_and_its_values_in_order(): void
    {
        $this->readyCloud();
        $template = $this->template(PartyMessageTemplate::query()->count() + 1, WhatsAppCloud::APPROVED);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBgM']]])]);
        $result = app(WhatsAppCloud::class)->send('8801711234567', $template, 'bn', [
            'party' => 'রহমান এন্টারপ্রাইজ', 'paper_no' => 'INV-0517', 'amount' => "86,400\n", 'date' => '21-11-2026',
        ]);

        $this->assertSame(['ok' => true, 'id' => 'wamid.HBgM', 'error' => null], $result);
        Http::assertSent(function (HttpRequest $r) use ($template): bool {
            $body = $r->data();

            return $r->method() === 'POST'
                && $r->url() === WhatsAppCloud::BASE.'/'.WhatsAppCloud::DEFAULT_VERSION.'/'.self::PHONE_ID.'/messages'
                && $body['messaging_product'] === 'whatsapp'
                && $body['to'] === '8801711234567'
                && $body['type'] === 'template'
                && $body['template']['name'] === $template->wa_name
                && $body['template']['language']['code'] === 'bn'
                && array_column($body['template']['components'][0]['parameters'], 'text') === ['রহমান এন্টারপ্রাইজ', 'INV-0517', '86,400']
                && $body['template']['components'][0]['type'] === 'body';
        });

        $template->forceFill(['wa_status' => WhatsAppCloud::PENDING])->save();
        $refused = app(WhatsAppCloud::class)->send('8801711234567', $template, 'bn', []);
        $this->assertFalse($refused['ok']);
        Http::assertSentCount(1);
    }

    public function test_the_webhook_refuses_a_wrong_token_or_signature(): void
    {
        $this->readyCloud();
        $verify = (string) $this->cloudRow()->secret('verify_token');
        $url = route('api.notification.whatsapp_webhook', $this->company->public_id);

        $this->get($url.'?hub.mode=subscribe&hub.verify_token='.urlencode($verify).'&hub.challenge=12345')
            ->assertOk()->assertSee('12345');
        $this->get($url.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();

        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'not-the-secret')], $body)
            ->assertForbidden();
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
        $this->signedPost($body)->assertOk();

        $this->call('POST', route('api.notification.whatsapp_webhook', '00000000-0000-7000-8000-000000000000'), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET)], $body)
            ->assertForbidden();
    }

    public function test_a_status_moves_the_message_and_a_failed_cloud_message_goes_by_sms(): void
    {
        $this->readyCloud();
        app(SettingsService::class)->set(PartyMessageDispatcher::SMS_ON, true);
        [, $hash] = SmsGatewayPhone::newToken();
        SmsGatewayPhone::query()->create(['name' => 'অফিসের ফোন ১', 'sim_number' => '01711000000', 'token_hash' => $hash, 'daily_limit' => 200, 'is_active' => true]);

        $delivered = $this->cloudMessage('wamid.AAA');
        $failed = $this->cloudMessage('wamid.BBB');
        $stranger = $this->cloudMessage('wamid.CCC');

        $this->signedPost(json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => self::WABA_ID,
            'changes' => [
                ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => self::PHONE_ID], 'statuses' => [
                    ['id' => 'wamid.AAA', 'status' => 'delivered'],
                    ['id' => 'wamid.BBB', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]],
                ]]],
                // ⛔ another number's news — not this company's
                ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '999'], 'statuses' => [
                    ['id' => 'wamid.CCC', 'status' => 'read'],
                ]]],
            ],
        ]]]))->assertOk()->assertJsonPath('data.accepted', 2);

        $this->assertSame(PartyMessage::DELIVERED, $delivered->fresh()->status);
        $this->assertNotNull($delivered->fresh()->delivered_at);
        $this->assertSame(PartyMessage::SENT, $stranger->fresh()->status, '⛔ another number moved this company\'s message');

        $failed->refresh();
        $this->assertSame(PartyMessage::FAILED, $failed->status);
        $this->assertSame('Message undeliverable', $failed->error);

        $sms = PartyMessage::query()->withoutGlobalScopes()->where('fallback_of_id', $failed->id)->first();
        $this->assertNotNull($sms, '⛔ the failed WhatsApp message did not fall back to SMS');
        $this->assertSame(PartyMessage::PHONE, $sms->route);
        $this->assertSame(PartyMessageTemplate::SMS, $sms->channel);
        $this->assertSame(PartyMessage::WAITING, $sms->status);
        $this->assertNotNull($sms->sms_outbox_id);
    }

    public function test_metas_template_verdict_reaches_the_template(): void
    {
        $this->readyCloud();
        $template = $this->template(1, WhatsAppCloud::PENDING);

        $update = fn (string $waba, string $event) => json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => $waba,
            'changes' => [['field' => 'message_template_status_update', 'value' => [
                'event' => $event, 'message_template_id' => 777, 'message_template_name' => $template->wa_name, 'message_template_language' => 'bn',
            ]]],
        ]]]);

        $this->signedPost($update('111', 'APPROVED'))->assertOk();
        $this->assertSame(WhatsAppCloud::PENDING, $template->fresh()->wa_status, '⛔ another business account approved this template');

        $this->signedPost($update(self::WABA_ID, 'APPROVED'))->assertOk();
        $this->assertSame(WhatsAppCloud::APPROVED, $template->fresh()->wa_status);
        $this->assertTrue(app(WhatsAppCloud::class)->canSend($template->fresh()));

        $this->signedPost($update(self::WABA_ID, 'REJECTED'))->assertOk();
        $this->assertSame(WhatsAppCloud::REJECTED, $template->fresh()->wa_status);
        $this->assertFalse(app(WhatsAppCloud::class)->canSend($template->fresh()));
    }

    public function test_a_template_goes_to_meta_numbered_with_examples(): void
    {
        $this->readyCloud();
        $template = $this->template(1, null);
        $template->forceFill(['wa_name' => null])->save();

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['id' => '5551', 'status' => 'PENDING', 'category' => 'UTILITY'])
            ->push(['id' => '5552', 'status' => 'PENDING', 'category' => 'UTILITY'])
            ->push(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'Body has too many variables']], 400)]);
        $this->post(route('notification.party_templates.meta_submit', $template))->assertSessionHas('saved');

        $template->refresh();
        $this->assertSame(WhatsAppCloud::PENDING, $template->wa_status);
        $this->assertStringStartsWith('abos_'.$template->id.'_', (string) $template->wa_name);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string) $template->wa_name);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === WhatsAppCloud::BASE.'/'.WhatsAppCloud::DEFAULT_VERSION.'/'.self::WABA_ID.'/message_templates'
            && $r['category'] === 'UTILITY' && $r['language'] === 'bn'
            && $r['components'][0]['text'] === "প্রিয় {{1}},\nবিল {{2}} মোট ৳{{3}}। ধন্যবাদ {{1}}।"
            && count($r['components'][0]['example']['body_text'][0]) === 3);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/message_templates') && $r['language'] === 'en');

        $this->post(route('notification.party_templates.meta_submit', $template))
            ->assertSessionHas('failed', __('notification::party_roads.submit_failed', ['error' => 'Body has too many variables']));

        $this->actingAs($this->clerk);
        $this->post(route('notification.party_templates.meta_submit', $template))->assertForbidden();
    }

    /** @param array<string, string> $fields */
    private function saveRoads(array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->put(route('notification.party_roads.save'), $fields + [
            'default_locale' => 'bn', 'phone_number_id' => self::PHONE_ID, 'waba_id' => self::WABA_ID,
        ]);
    }

    private function cloudRow(): NotificationChannel
    {
        return NotificationChannel::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('channel', NotificationChannel::PARTY_WHATSAPP)->firstOrFail();
    }

    private function readyCloud(): void
    {
        $this->saveRoads(['cloud' => '1', 'token' => self::TOKEN, 'app_secret' => self::APP_SECRET]);
        $this->cloudRow()->forceFill(['last_check_ok' => true, 'last_checked_at' => now()])->save();
    }

    private function template(int $n, ?string $status): PartyMessageTemplate
    {
        $template = PartyMessageTemplate::query()->create([
            'company_id' => $this->company->id, 'channel' => PartyMessageTemplate::WHATSAPP, 'document_type' => 'sales_invoice',
            'name' => 'Sales invoice '.$n, 'is_active' => true, 'sort' => 99,
            'body_bn' => "প্রিয় {party},\nবিল {paper_no} মোট ৳{amount}। ধন্যবাদ {party}।",
            'body_en' => "Dear {party},\nInvoice {paper_no}, total Tk {amount}. Thank you {party}.",
        ]);
        $template->forceFill(['wa_name' => 'abos_'.$template->id.'_sales_invoice_'.$n, 'wa_status' => $status])->save();

        return $template;
    }

    private function cloudMessage(string $ref): PartyMessage
    {
        return PartyMessage::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'channel' => PartyMessageTemplate::WHATSAPP, 'document_type' => 'sales_invoice', 'document_id' => 1, 'document_no' => 'INV-1',
            'party_name' => 'রহমান এন্টারপ্রাইজ', 'phone' => '8801711234567', 'locale' => 'bn', 'body' => 'প্রিয় রহমান এন্টারপ্রাইজ',
            'status' => PartyMessage::SENT, 'route' => PartyMessage::CLOUD, 'via' => PartyMessage::AUTO, 'provider_ref' => $ref, 'sent_at' => now(),
        ]);
    }

    private function signedPost(string $body): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', route('api.notification.whatsapp_webhook', $this->company->public_id), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET)], $body);
    }
}
