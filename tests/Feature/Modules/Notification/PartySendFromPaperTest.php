<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\PartyMessageDispatcher;
use App\Core\Services\PartyMessages;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentShare;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\SmsOutbox;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ কাগজ থেকে পাঠানো — ধাপ ৩ (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব ক)।
 *
 * দাবি: ⋯ মেনুর "ছাপা ও পাঠানো"-তে লিংক কপি · WhatsApp-এ পাঠান · SMS পাঠান — WhatsApp কেবল "বোতাম চাপলে" পথ চালু থাকলে, SMS কেবল
 * SMS-এর পথ চালু আর অফিসের ফোন বসানো থাকলে; মেনুর বোতাম পপআপটা ঐ পথ বাছা অবস্থায় খোলে; SMS যায় পাঠানোর একমাত্র দরজা দিয়ে
 * (পথ "ফোন", অবস্থা "অপেক্ষায়", সারির নম্বর খাতায়); WhatsApp-এর পথ বন্ধ থাকলে হাতে বানানো অনুরোধও ফেরে; SMS-এ কেবল SMS-এর
 * লেখা, WhatsApp-এ কেবল WhatsApp-এর; টাকা-পাওয়ার ভাউচার রসিদের লেখা পায়; নম্বরের তালিকায় পার্টির খাতার সব মোবাইল।
 */
final class PartySendFromPaperTest extends TestCase
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
            'company_id' => $this->company->id, 'code' => 'SND-1', 'name_en' => 'Rahman Enterprise', 'name_bn' => 'রহমান এন্টারপ্রাইজ',
            'owner_name' => 'রহমান', 'phone' => '01711-234567', 'is_active' => true,
        ]);

        $this->voucher = $this->voucherFor($this->customer);
    }

    public function test_the_menu_shows_whatsapp_and_sms_only_when_their_roads_are_on(): void
    {
        // ⓘ শুরুতে: wa.me চালু (নিজের মান), অফিসের ফোন নেই → SMS নেই
        $html = $this->menu();
        $this->assertStringContainsString(__('core.row_menu.copy_link'), $html, '⛔ "লিংক কপি" হারিয়েছে।');
        $this->assertStringContainsString(__('core.row_menu.send_whatsapp'), $html, '⛔ WhatsApp-এর পথ চালু, অথচ মেনুতে নেই।');
        $this->assertStringContainsString('name="channel" value="whatsapp"', $html, '⛔ WhatsApp-এর সারি পপআপটা পথ বাছা অবস্থায় খোলে না।');
        $this->assertStringNotContainsString(__('core.row_menu.send_sms'), $html, '⛔ অফিসের ফোন নেই, অথচ "SMS পাঠান" আছে — মৃত বোতাম।');

        $this->newPhone();
        $html = $this->menu();
        $this->assertStringContainsString(__('core.row_menu.send_sms'), $html, '⛔ ফোন বসানো আর পথ চালু, অথচ "SMS পাঠান" নেই।');
        $this->assertStringContainsString('name="channel" value="sms"', $html);

        app(SettingsService::class)->set(PartyMessageDispatcher::SMS_ON, false);
        app(SettingsService::class)->set(PartyMessageDispatcher::WAME_ON, false);
        $html = $this->menu();
        $this->assertStringNotContainsString(__('core.row_menu.send_sms'), $html, '⛔ SMS-এর পথ বন্ধ, অথচ মেনুতে আছে।');
        $this->assertStringNotContainsString(__('core.row_menu.send_whatsapp'), $html, '⛔ WhatsApp-এর পথ বন্ধ, অথচ মেনুতে আছে।');
        $this->assertStringContainsString(__('core.row_menu.copy_link'), $html, '⛔ পথ বন্ধ হলেও লিংক কপি থাকার কথা।');
    }

    public function test_the_paper_page_menu_follows_the_same_switches(): void
    {
        $html = $this->get(route('accounts.voucher.show', $this->voucher))->assertOk()->getContent();

        $this->assertStringContainsString('data-send-channel="link"', $html, '⛔ কাগজের পাতার ছাপার মেনুতে "লিংক কপি" নেই।');
        $this->assertStringContainsString('data-send-channel="whatsapp"', $html);
        $this->assertStringNotContainsString('data-send-channel="sms"', $html, '⛔ অফিসের ফোন নেই, অথচ কাগজের পাতায় "SMS পাঠান"।');
    }

    public function test_a_send_item_opens_the_popup_on_its_road(): void
    {
        $this->newPhone();

        $this->share(PartyMessageTemplate::SMS)->assertSessionHas('shared_channel', PartyMessageTemplate::SMS);
        $this->share(PartyMessageTemplate::WHATSAPP)->assertSessionHas('shared_channel', PartyMessageTemplate::WHATSAPP);
        // ⓘ পথ না দিলে চ্যানেল খালি — Laravel-এর session()->has() null মানকে "নেই" ধরে, তাই assertSessionMissing
        $this->share(null)->assertSessionHas('shared_link')->assertSessionMissing('shared_channel');

        $this->post(route('paper.share'), $this->shareFields() + ['channel' => 'email'])->assertSessionHasErrors('channel');
    }

    public function test_an_sms_goes_through_the_dispatcher_with_its_road_state_and_queue_row(): void
    {
        $this->newPhone();
        $share = $this->shareRow();
        $template = $this->smsText();

        $this->post(route('paper.sms', $share), ['template_id' => $template->id, 'locale' => 'bn', 'phone' => '01711-234567'])
            ->assertRedirect()->assertSessionHas('sms_queued')->assertSessionHas('shared_channel', PartyMessageTemplate::SMS);

        $row = PartyMessage::query()->sole();
        $queued = SmsOutbox::query()->sole();

        $this->assertSame(PartyMessageTemplate::SMS, $row->channel);
        $this->assertSame(PartyMessage::PHONE, $row->route, '⛔ SMS-এর সারির পথ "ফোন" নয় — দরজাটা পাশ কাটানো হয়েছে।');
        $this->assertSame(PartyMessage::WAITING, $row->status, '⛔ সারিতে বসা SMS "অপেক্ষায়" নয়।');
        $this->assertSame((int) $queued->id, (int) $row->sms_outbox_id, '⛔ খাতার সারি জানে না কোন সারির SMS — ফোনের খবর এসে পৌঁছাবে না।');
        $this->assertSame((string) $row->body, (string) $queued->body, '⛔ খাতার লেখা আর SMS-এর লেখা আলাদা।');
        $this->assertSame(SmsOutbox::PARTY, $queued->source);
        $this->assertSame((int) $row->id, (int) $queued->source_id);
    }

    public function test_whatsapp_is_refused_when_its_road_is_off(): void
    {
        $share = $this->shareRow();
        $chosen = app(PartyMessages::class)->box($share->id)['choices'][0];

        app(SettingsService::class)->set(PartyMessageDispatcher::WAME_ON, false);

        $this->post(route('paper.whatsapp', $share), ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01711234567'])
            ->assertForbidden();

        $this->assertSame(0, PartyMessage::query()->count(), '⛔ বন্ধ পথেও খাতায় সারি পড়েছে।');
    }

    public function test_each_road_offers_and_takes_only_its_own_texts(): void
    {
        $this->newPhone();
        $share = $this->shareRow();
        $sms = $this->smsText();

        $box = app(PartyMessages::class)->box($share->id);

        $this->assertTrue($box['sms'], '⛔ ফোন বসানো, অথচ বাক্স বলে SMS-এর পথ বন্ধ।');
        $this->assertContains($sms->name, array_column($box['sms_choices'], 'name'));
        $this->assertContains('বাকির তাগাদা', array_column($box['sms_choices'], 'name'), '⛔ পার্টির হিসাবের SMS লেখা কাগজের পপআপে নেই।');
        $this->assertNotContains('পরিশোধের খবর', array_column($box['sms_choices'], 'name'), '⛔ WhatsApp-এর লেখা SMS-এর তালিকায়।');
        $this->assertNotContains($sms->name, array_column($box['choices'], 'name'), '⛔ SMS-এর লেখা WhatsApp-এর তালিকায়।');

        $whatsappText = PartyMessageTemplate::query()->where('name', 'পরিশোধের খবর')->firstOrFail();
        $this->post(route('paper.sms', $share), ['template_id' => $whatsappText->id, 'locale' => 'bn', 'phone' => '01711234567'])
            ->assertRedirect()->assertSessionHas('sms_error');

        $this->post(route('paper.whatsapp', $share), ['template_id' => $sms->id, 'locale' => 'bn', 'phone' => '01711234567'])
            ->assertSessionHasErrors(['template_id' => __('core.print.wa_template_gone')]);

        $this->assertSame(0, PartyMessage::query()->count());
        $this->assertSame(0, SmsOutbox::query()->count());
    }

    public function test_a_money_in_voucher_gets_the_receipt_texts_not_the_payment_one(): void
    {
        $this->newPhone();
        $this->voucher->forceFill(['type' => Voucher::RECEIPT])->saveQuietly();

        $box = app(PartyMessages::class)->box($this->shareRow()->id);

        $this->assertContains('টাকা পাওয়ার রসিদ', array_column($box['choices'], 'name'), '⛔ RCV-তে রসিদের লেখা নেই।');
        $this->assertNotContains('পরিশোধের খবর', array_column($box['choices'], 'name'), '⛔ RCV-তে "আপনাকে পরিশোধ করেছে" — উল্টো কথা।');
        $this->assertContains('টাকা পেয়েছি (ছোট)', array_column($box['sms_choices'], 'name'));
    }

    public function test_the_number_list_brings_every_mobile_of_the_party_with_its_owner(): void
    {
        $box = app(PartyMessages::class)->box($this->shareRow()->id);

        $this->assertSame(['8801711234567'], array_column($box['phones'], 'phone'));
        $this->assertSame('01711234567', $box['phones'][0]['shown']);
        $this->assertSame('রহমান', $box['phones'][0]['label'], '⛔ নম্বরের পাশে মালিকের নাম নেই।');
    }

    private function menu(): string
    {
        // ⓘ পথের প্রশ্ন অনুরোধ ধরে মনে রাখা — একই পরীক্ষায় সুইচ বদলালে নতুন করে
        request()->attributes->remove('abos.row-menu.send-roads');

        return view('accounts::voucher.partials.row-menu', ['voucher' => $this->voucher->fresh()])->render();
    }

    /** @return array<string, mixed> */
    private function shareFields(): array
    {
        return [
            'route' => 'accounts.voucher.print',
            'params' => ['voucher' => $this->voucher->id],
            'document_type' => 'accounts_voucher',
            'document_id' => $this->voucher->id,
            'paper' => PaperSize::A4,
        ];
    }

    private function share(?string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('paper.share'), $this->shareFields() + array_filter(['channel' => $channel]))->assertRedirect();
    }

    private function shareRow(): DocumentShare
    {
        $this->share(null);

        return DocumentShare::query()->latest('id')->firstOrFail();
    }

    private function smsText(): PartyMessageTemplate
    {
        PartyMessageTemplate::ensureStarters((int) $this->company->id);

        return PartyMessageTemplate::query()->create([
            'company_id' => $this->company->id, 'channel' => PartyMessageTemplate::SMS, 'document_type' => 'accounts_voucher',
            'name' => 'ভাউচার (SMS)', 'body_bn' => 'প্রিয় {party}, ৳{amount} — {paper_no}', 'body_en' => 'Dear {party}, Tk {amount} — {paper_no}',
            'is_active' => true, 'sort' => 10,
        ]);
    }

    private function newPhone(): void
    {
        $this->post(route('notification.sms_phones.store'), ['name' => 'অফিসের ফোন ১', 'sim_number' => '01711000000', 'daily_limit' => 200])
            ->assertRedirect()->assertSessionHas('sms_phone_token');
    }

    private function voucherFor(Customer $customer): Voucher
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

        $voucher = $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'send from paper'],
            [
                ['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'],
                ['account_id' => $capital->id, 'debit' => '0', 'credit' => '1000'],
            ],
        ));

        $voucher->forceFill(['party_type' => 'customer', 'party_id' => $customer->id])->saveQuietly();

        return $voucher->fresh();
    }
}
