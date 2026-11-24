<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PartyMessageDispatcher;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\SmsGatewayPhone;
use App\Models\SmsOutbox;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ পার্টিকে WhatsApp ও SMS — ফোনের দরজা (ধাপ ৩, মালিকের "ok" ২৩ নভেম্বর ২০২৬; চুক্তি docs/"Contract — পার্টিকে WhatsApp ও SMS (অ্যাপ).md")।
 *
 * দাবি: পপআপের তথ্য (পার্টি, নম্বর, ঐ পথের লেখা আসল মান বসানো, পথের সুইচ, অফিসের ফোন); WhatsApp খাতায় "খোলা হয়েছে" লিখে wa.me
 * ফেরায়; SMS পাঠানোর দরজা দিয়ে সারিতে বসে; কাগজের "পার্টিকে জানানো" ঘর অবস্থা, সময়, কারণ আর "SMS-এ গেছে" দেয়; আবার পাঠানো
 * নতুন সারি; আর ওয়েবের "পাঠান" বাক্সের পাহারা — ছাপার চাবি নেই ৪০৩, নাগালের বাইরে ৪০৪, ভুল লেখা/নম্বর ৪২২, চাবি ছাড়া লেখা ৪২৮।
 */
final class PartyMessagesApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->customer = Customer::query()->create([
            'company_id' => $this->company->id, 'code' => 'API-1', 'name_en' => 'Rahim Traders', 'name_bn' => 'রহিম ট্রেডার্স',
            'phone' => '01712-345678', 'is_active' => true,
        ]);
        $this->voucher = $this->voucherFor($this->customer);
        PartyMessageTemplate::ensureStarters((int) $this->company->id);
    }

    public function test_compose_brings_the_party_its_numbers_the_roads_texts_and_the_switches(): void
    {
        $this->officePhone();

        $data = $this->phone($this->owner)->getJson('/api/v1/party-messages/compose?'.http_build_query($this->paper() + ['channel' => 'whatsapp']))
            ->assertOk()->assertHeader('Cache-Control')->json('data');

        $this->assertSame('রহিম ট্রেডার্স', $data['party']['name']);
        $this->assertContains('8801712345678', array_column($data['phones'], 'number'), '⛔ পার্টির নম্বর আসেনি');
        $this->assertNotEmpty($data['templates']);
        $first = $data['templates'][0];
        $this->assertSame(['id', 'name', 'bn', 'en'], array_keys($first));
        $this->assertStringContainsString((string) $this->voucher->document_no, $first['bn'], '⛔ লেখায় আসল মান বসেনি');
        $this->assertStringNotContainsString('{', $first['bn'].$first['en']);
        $this->assertTrue($data['wame_on']);
        $this->assertTrue($data['sms_on']);
        $this->assertSame('অফিসের ফোন ১', $data['office_phone']);
        $this->assertContains($data['locale'], ['bn', 'en']);

        // ⓘ অফিসের ফোন না থাকলে SMS বন্ধ, ফোনের নামও নেই
        SmsGatewayPhone::query()->update(['is_active' => false]);
        $off = $this->phone($this->owner)->getJson('/api/v1/party-messages/compose?'.http_build_query($this->paper() + ['channel' => 'sms']))
            ->assertOk()->json('data');
        $this->assertFalse($off['sms_on']);
        $this->assertNull($off['office_phone']);
    }

    public function test_whatsapp_logs_an_opened_row_and_returns_the_wame_link(): void
    {
        $chosen = $this->firstTemplate('whatsapp');

        $response = $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/whatsapp', $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'en', 'phone' => '+880 1712-345678', 'body' => 'অন্য কথা'])
            ->assertCreated();

        $row = PartyMessage::query()->sole();
        $this->assertSame(PartyMessage::OPENED, $row->status);
        $this->assertSame(PartyMessage::WAME, $row->route);
        $this->assertSame($chosen['en'], $row->body, '⛔ লেখা সার্ভারে বানানো নয় — পাতার লেখা নেওয়া হয়েছে');
        $this->assertSame('https://wa.me/8801712345678?text='.rawurlencode($chosen['en']), $response->json('url'));
        $this->assertSame((string) $row->public_id, $response->json('data.id'));
        $this->assertSame('opened', $response->json('data.status'));
        $this->assertSame(0, SmsOutbox::query()->count());
    }

    public function test_sms_goes_through_the_dispatcher_into_the_office_queue(): void
    {
        $this->officePhone();
        $chosen = $this->firstTemplate('sms');

        $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/sms', $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertCreated()->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.route', 'phone');

        $row = PartyMessage::query()->sole();
        $queued = SmsOutbox::query()->where('source', SmsOutbox::PARTY)->where('source_id', $row->id)->sole();
        $this->assertSame((int) $queued->id, (int) $row->sms_outbox_id, '⛔ পাঠানোর দরজা (viaSms) দিয়ে যায়নি');
        $this->assertSame($chosen['bn'], $queued->body);
    }

    public function test_sms_is_refused_abroad_and_when_the_road_is_closed(): void
    {
        $chosen = $this->firstTemplate('sms');

        // ⓘ অফিসের ফোন নেই
        $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/sms', $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->officePhone();
        $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/sms', $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '+44 7911 123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        // ⓘ wa.me বন্ধ
        app(SettingsService::class)->set(PartyMessageDispatcher::WAME_ON, false);
        $wa = $this->firstTemplate('whatsapp');
        $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/whatsapp', $this->paper() + ['template_id' => $wa['id'], 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertForbidden();

        $this->assertSame(0, PartyMessage::query()->count());
    }

    public function test_bad_text_bad_number_and_a_missing_key_are_refused(): void
    {
        $chosen = $this->firstTemplate('whatsapp');
        $invoice = PartyMessageTemplate::query()->where('document_type', 'sales_invoice')->firstOrFail();

        $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/whatsapp', $this->paper() + ['template_id' => $invoice->id, 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('template_id');

        $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/whatsapp', $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '02-9123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        // ⓘ নতুন দরজা — Idempotency-Key ছাড়া লেখা ফেরে। ⚠️ withHeader() পরীক্ষার পরের সব অনুরোধে থেকে যায় — আগে মুছে নেওয়া,
        //   নাহলে আগের চাবি নিয়েই যেত আর ২০১ ফিরত
        $this->flushHeaders();
        $this->phone($this->owner)
            ->postJson('/api/v1/party-messages/whatsapp', $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertStatus(428);

        $this->assertSame(0, PartyMessage::query()->count());
    }

    public function test_the_same_key_twice_sends_once(): void
    {
        $this->officePhone();
        $chosen = $this->firstTemplate('sms');
        $key = Str::random(16);
        $body = $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678'];

        $this->phone($this->owner)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/party-messages/sms', $body)->assertCreated();
        $this->phone($this->owner)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/party-messages/sms', $body)->assertCreated();

        $this->assertSame(1, SmsOutbox::query()->count(), '⛔ একই চাপ দুইবার এলে পার্টি দুইটা SMS পেল');
    }

    public function test_the_papers_box_lists_each_message_with_its_state_reason_and_fallback(): void
    {
        $cloud = $this->message(['status' => PartyMessage::FAILED, 'route' => PartyMessage::CLOUD, 'via' => PartyMessage::AUTO, 'error' => 'এই নম্বরে WhatsApp নেই']);
        $sms = $this->message(['status' => PartyMessage::DELIVERED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'via' => PartyMessage::AUTO,
            'fallback_of_id' => $cloud->id, 'delivered_at' => now()]);
        // ⓘ অন্য কাগজের সারি এখানে আসে না
        $this->message(['document_id' => $this->voucher->id + 999, 'document_no' => 'OTHER']);

        $rows = $this->phone($this->owner)->getJson('/api/v1/party-messages?'.http_build_query($this->paper()))->assertOk()->json('data');

        $this->assertSame([(string) $sms->public_id, (string) $cloud->public_id], array_column($rows, 'id'), '⛔ নতুন আগে, কেবল এই কাগজের');
        $this->assertSame('delivered', $rows[0]['status']);
        $this->assertNull($rows[0]['reason']);
        $this->assertNotNull($rows[0]['time']);
        $this->assertSame('failed', $rows[1]['status']);
        $this->assertSame('এই নম্বরে WhatsApp নেই', $rows[1]['reason']);
        $this->assertTrue($rows[1]['fell_back'], '⛔ "SMS-এ গেছে" ফোনে জানানো হয়নি');
        $this->assertFalse($rows[0]['fell_back']);
    }

    public function test_resend_makes_a_new_row_on_the_same_road(): void
    {
        $this->officePhone();
        $old = $this->message(['status' => PartyMessage::FAILED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'error' => 'সিম বন্ধ']);

        $response = $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/'.$old->public_id.'/resend')
            ->assertCreated()->assertJsonPath('url', null)->assertJsonPath('data.route', 'phone');

        $new = PartyMessage::query()->where('id', '>', $old->id)->sole();
        $this->assertSame((string) $new->public_id, $response->json('data.id'));
        $this->assertTrue(SmsOutbox::query()->where('source_id', $new->id)->exists());
        $this->assertSame(PartyMessage::FAILED, $old->fresh()->status);

        // ⓘ wa.me-র সারি — আবার wa.me লিংক
        $wa = $this->message(['status' => PartyMessage::OPENED]);
        $url = $this->phone($this->owner)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/'.$wa->public_id.'/resend')->assertCreated()->json('url');
        $this->assertStringStartsWith('https://wa.me/8801712345678?text=', (string) $url);
    }

    public function test_keys_and_reach_are_the_web_share_boxs(): void
    {
        $this->officePhone();
        $chosen = $this->firstTemplate('whatsapp');
        $row = $this->message(['status' => PartyMessage::FAILED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'error' => 'x']);

        // ⓘ কাগজের ছাপার চাবি নেই — ৪০৩, সব দরজায়
        $stranger = $this->person([]);
        $this->phone($stranger)->getJson('/api/v1/party-messages/compose?'.http_build_query($this->paper() + ['channel' => 'whatsapp']))->assertForbidden();
        $this->phone($stranger)->getJson('/api/v1/party-messages?'.http_build_query($this->paper()))->assertForbidden();
        $this->phone($stranger)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/whatsapp', $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertForbidden();
        $this->phone($stranger)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/'.$row->public_id.'/resend')->assertForbidden();
        $this->phone($stranger)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/sms', $this->paper() + ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertForbidden();

        // ⓘ পার্টির হিসাবের সারি (কাগজ নয়) — ওয়েবের খাতার চাবি ছাড়া ৪০৩
        $account = $this->message(['document_type' => 'party_account', 'document_id' => 0, 'status' => PartyMessage::FAILED]);
        $this->phone($stranger)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/'.$account->public_id.'/resend')->assertForbidden();
        $account->forceDelete();

        // ⓘ অচেনা ধরন, অচেনা কাগজ, ক্রমিক সংখ্যা — ৪০৪
        $this->phone($this->owner)->getJson('/api/v1/party-messages/compose?type=Nothing&id='.$this->voucher->public_id.'&channel=sms')->assertNotFound();
        $this->phone($this->owner)->getJson('/api/v1/party-messages/compose?type=Voucher&id='.Str::uuid().'&channel=sms')->assertNotFound();
        $this->phone($this->owner)->getJson('/api/v1/party-messages/compose?type=Voucher&id='.$this->voucher->id.'&channel=sms')->assertNotFound();

        // ⓘ শাখার দেয়াল — অন্য শাখায় আটকানো মানুষের কাছে এই কাগজ আর এর সারি নেই
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->voucher->forceFill(['branch_id' => $ntk->id])->saveQuietly();
        $row->forceFill(['branch_id' => $ntk->id])->saveQuietly();
        $clerk = $this->person(['accounts.voucher.print', 'accounts.voucher.view', 'accounts.report'], $mms);
        \App\Models\UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => \App\Models\UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(\App\Core\Services\DataScope::class)->forget();

        $this->phone($clerk)->getJson('/api/v1/party-messages?'.http_build_query($this->paper()))->assertNotFound();
        $this->phone($clerk)->withHeader('Idempotency-Key', Str::random(16))
            ->postJson('/api/v1/party-messages/'.$row->public_id.'/resend')->assertNotFound();

        $this->assertSame(1, PartyMessage::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, SmsOutbox::query()->count());
    }

    /**
     * ⭐ {due} — শাখার দেয়ালে আটকানো মানুষ কেবল নিজের শাখার (আর শাখাহীন) বাকি দেখেন ও পাঠান; সব শাখার মানুষ গোটাটা, হেডারে এক
     * শাখা বাছলেও; নিজে থেকে পাঠানোয় গোটাটা (মালিক, ২৪ নভেম্বর ২০২৬)।
     */
    public function test_the_due_stays_inside_the_senders_branch_wall(): void
    {
        $branch = fn (string $code) => Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
        [$mms, $ntk] = [$branch('MMS'), $branch('NTK')];
        $this->voucher->forceFill(['branch_id' => $mms->id])->saveQuietly();
        \App\Models\LedgerEntry::query()->forParty('customer', (int) $this->customer->id)->delete();

        $year = (int) \App\Models\LedgerEntry::query()->value('financial_year_id');
        foreach ([[$mms->id, '700'], [$ntk->id, '2000'], [null, '30']] as [$branchId, $debit]) {
            \App\Models\LedgerEntry::query()->create([
                'company_id' => $this->company->id, 'branch_id' => $branchId, 'financial_year_id' => $year,
                'account_id' => Account::query()->where('is_group', false)->firstOrFail()->id,
                'party_type' => 'customer', 'party_id' => $this->customer->id, 'trx_date' => now()->toDateString(),
                'debit' => $debit, 'credit' => '0', 'source_type' => 'due_wall_test', 'source_id' => $this->customer->id,
                'narration' => 'due wall', 'created_by' => $this->owner->id,
            ]);
        }

        $template = PartyMessageTemplate::query()->where('document_type', 'accounts_voucher')->where('channel', 'whatsapp')->firstOrFail();
        $template->update(['body_bn' => 'বাকি [{due}]', 'body_en' => 'Due [{due}]']);

        $clerk = $this->person(['accounts.voucher.print', 'accounts.voucher.view', 'accounts.report'], $mms);
        \App\Models\UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => \App\Models\UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(\App\Core\Services\DataScope::class)->forget();

        foreach ([[$clerk, '730'], [$this->owner, '2,730']] as [$who, $due]) {
            $seen = collect($this->phone($who)->getJson('/api/v1/party-messages/compose?'.http_build_query($this->paper() + ['channel' => 'whatsapp']))
                ->assertOk()->json('data.templates'))->firstWhere('id', $template->id);
            $this->assertSame('বাকি ['.$due.']', $seen['bn'] ?? null, '⛔ পপআপের বাকি ভুল — '.$who->name);

            $this->phone($who)->withHeader('Idempotency-Key', Str::random(16))
                ->postJson('/api/v1/party-messages/whatsapp', $this->paper() + ['template_id' => $template->id, 'locale' => 'en', 'phone' => '01712345678'])
                ->assertCreated();
            $sent = PartyMessage::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
            $this->assertSame('Due ['.$due.']', $sent->body, '⛔ পাঠানো লেখার বাকি ভুল — '.$who->name);

            $this->phone($who)->withHeader('Idempotency-Key', Str::random(16))
                ->postJson('/api/v1/party-messages/'.$sent->public_id.'/resend')->assertCreated();
            $this->assertSame('Due ['.$due.']', PartyMessage::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->body, '⛔ আবার পাঠানোর বাকি ভুল — '.$who->name);
        }

        $messages = app(\App\Core\Services\PartyMessages::class);
        $this->assertSame('2730.0000', $messages->dueOf('customer', (int) $this->customer->id), '⛔ নিজে থেকে পাঠানোয় গোটা বাকি নয়');

        // ⓘ হেডারে এক শাখা বাছা, কিন্তু দেয়াল নেই — তবু গোটা বাকি
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $mms->id])->save();
        $this->assertSame('2730.0000', $messages->dueOf('customer', (int) $this->customer->id, $this->owner->fresh()));
    }

    /** @return array{type: string, id: string} */
    private function paper(): array
    {
        return ['type' => 'Voucher', 'id' => (string) $this->voucher->public_id];
    }

    /** @return array{id: int, name: string, bn: string, en: string} */
    private function firstTemplate(string $channel): array
    {
        $smsOn = SmsGatewayPhone::query()->where('is_active', true)->exists();
        if ($channel === 'sms' && ! $smsOn) {
            // ⓘ লেখা বাছতে পপআপটা ফোন ছাড়াও খোলে — SMS-এর লেখাও আসে
            $this->officePhone();
        }

        $templates = $this->phone($this->owner)
            ->getJson('/api/v1/party-messages/compose?'.http_build_query($this->paper() + ['channel' => $channel]))
            ->assertOk()->json('data.templates');

        if ($channel === 'sms' && ! $smsOn) {
            SmsGatewayPhone::query()->delete();
        }

        $this->assertNotEmpty($templates, '⛔ ঐ পথের কোনো লেখা আসেনি — '.$channel);

        return $templates[0];
    }

    /** @param  array<string, mixed>  $over */
    private function message(array $over = []): PartyMessage
    {
        return PartyMessage::query()->forceCreate(array_merge([
            'company_id' => $this->company->id,
            'branch_id' => $this->voucher->branch_id,
            'channel' => PartyMessageTemplate::WHATSAPP,
            'document_type' => 'accounts_voucher',
            'document_id' => $this->voucher->id,
            'document_no' => $this->voucher->document_no,
            'party_type' => 'customer',
            'party_id' => $this->customer->id,
            'party_name' => 'রহিম ট্রেডার্স',
            'phone' => '8801712345678',
            'locale' => 'bn',
            'template_id' => PartyMessageTemplate::query()->where('document_type', 'accounts_voucher')->value('id'),
            'body' => 'লেখা',
            'sent_by' => $this->owner->id,
            'status' => PartyMessage::OPENED,
            'route' => PartyMessage::WAME,
            'via' => PartyMessage::BUTTON,
        ], $over))->fresh();
    }

    private function officePhone(): void
    {
        [, $hash] = SmsGatewayPhone::newToken();
        SmsGatewayPhone::query()->create(['company_id' => $this->company->id, 'name' => 'অফিসের ফোন ১', 'token_hash' => $hash,
            'daily_limit' => 200, 'is_active' => true]);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id,
            'current_branch_id' => ($branch ?? $this->company->defaultBranch())?->id])->save();

        if ($keys !== []) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
                array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }

    private function voucherFor(Customer $customer): Voucher
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

        $voucher = $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'party api test'],
            [
                ['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'],
                ['account_id' => $capital->id, 'debit' => '0', 'credit' => '1000'],
            ],
        ));

        $voucher->forceFill(['party_type' => 'customer', 'party_id' => $customer->id])->saveQuietly();

        return $voucher->fresh();
    }
}
