<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\PartyMessages;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentShare;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ পার্টির WhatsApp-এ — পার্টির নম্বরে, বেছে নেওয়া লেখায়, বাংলা বা English-এ, আর খাতায় লেখা
 * (মালিকের "ok", ১০ নভেম্বর ২০২৬; নমুনা U1X687u5MkwVfEL7ci7MGv)।
 *
 * ⚠️ আগে: "পাঠান"-এর বোতাম খুলত `wa.me/?text=` — নম্বর ছাড়া, একটাই লেখা, কোনো খাতা নেই।
 */
final class APaperWentToWhatsAppWithoutThePartyNumberTest extends TestCase
{
    use RefreshDatabase;

    private const KIND = 'accounts_voucher';

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
            'company_id' => $this->company->id, 'code' => 'WA-1', 'name_en' => 'Rahim Traders', 'name_bn' => 'রহিম ট্রেডার্স',
            'phone' => '01712-345678', 'is_active' => true,
        ]);

        $this->voucher = $this->voucherFor($this->customer);
    }

    public function test_the_box_brings_the_partys_number_and_the_papers_own_text_first(): void
    {
        $box = app(PartyMessages::class)->box($this->share()->id);

        $this->assertNotNull($box, 'বাক্সটা পার্টির কিছুই আনেনি — পুরনো নম্বরহীন বোতামই রয়ে গেছে।');
        $this->assertSame('8801712345678', $box['party']['phone'], 'পার্টির খাতার নম্বরটা WhatsApp-এর রূপে বসেনি।');
        $this->assertSame('পরিশোধের খবর', $box['choices'][0]['name'] ?? null,
            'ভাউচারের নিজের লেখা আগে আসার কথা, "যেকোনো কাগজ"-এর লেখা পরে।');
        $this->assertSame(['পরিশোধের খবর', 'সাধারণ — যেকোনো কাগজ'], array_column($box['choices'], 'name'),
            'অন্য কাগজের লেখা (বিল, রসিদ…) ভাউচারের বাক্সে এসেছে।');

        $first = $box['choices'][0];
        $this->assertStringContainsString((string) $this->voucher->document_no, $first['bn']);
        $this->assertStringContainsString('রহিম ট্রেডার্স', $first['bn'], 'বাংলা লেখায় পার্টির নাম বসেনি।');
        $this->assertStringContainsString('Rahim Traders', $first['en'], 'English লেখায় পার্টির নাম English-এ বসেনি।');
        $this->assertStringContainsString($this->share()->token, $first['en'], 'লেখায় কাগজের লিংক বসেনি।');
        $this->assertStringNotContainsString('{', $first['bn'].$first['en'], 'লেখায় কোনো নাম না-বসানো রয়ে গেছে।');
    }

    public function test_whatsapp_opens_on_the_partys_number_in_the_chosen_language_and_is_logged(): void
    {
        $share = $this->share();
        $box = app(PartyMessages::class)->box($share->id);
        $chosen = $box['choices'][0];

        $response = $this->post(route('paper.whatsapp', $share), [
            'template_id' => $chosen['id'], 'locale' => 'en', 'phone' => '+880 1712-345678',
        ]);

        $response->assertRedirect('https://wa.me/8801712345678?text='.rawurlencode($chosen['en']));

        $row = PartyMessage::query()->sole();
        $this->assertSame('8801712345678', $row->phone);
        $this->assertSame('en', $row->locale, 'খাতায় ভাষা ভুল।');
        $this->assertSame($chosen['en'], $row->body, 'খাতার লেখা আর পার্টির কাছে যাওয়া লেখা আলাদা।');
        $this->assertSame((int) $this->owner->id, (int) $row->sent_by, 'কে পাঠালেন, খাতায় নেই।');
        $this->assertSame(self::KIND, $row->document_type);
        $this->assertSame((int) $this->voucher->id, (int) $row->document_id);
        $this->assertSame((int) $this->customer->id, (int) $row->party_id, 'কোন পার্টিকে গেল, খাতায় নেই।');

        $this->get(route('notification.party_messages.index'))->assertOk()
            ->assertSee('data-party-message="'.$row->id.'"', false)
            ->assertSee('+8801712345678');
    }

    public function test_the_text_is_built_on_the_server_not_taken_from_the_page(): void
    {
        $share = $this->share();
        $chosen = app(PartyMessages::class)->box($share->id)['choices'][0];

        $response = $this->post(route('paper.whatsapp', $share), [
            'template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678', 'body' => 'অন্য কথা',
        ]);

        $response->assertRedirect('https://wa.me/8801712345678?text='.rawurlencode($chosen['bn']));
        $this->assertSame($chosen['bn'], PartyMessage::query()->sole()->body);
    }

    public function test_a_number_that_is_not_a_mobile_is_refused_and_nothing_is_logged(): void
    {
        $share = $this->share();
        $chosen = app(PartyMessages::class)->box($share->id)['choices'][0];

        $this->post(route('paper.whatsapp', $share), ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '02-9123456'])
            ->assertSessionHasErrors(['phone' => __('core.print.wa_phone_bad')]);

        $this->assertSame(0, PartyMessage::query()->count(), 'ভুল নম্বরেও খাতায় সারি পড়েছে।');
    }

    public function test_a_text_for_another_paper_or_switched_off_is_refused(): void
    {
        $share = $this->share();
        app(PartyMessages::class)->box($share->id);

        $invoice = PartyMessageTemplate::query()->where('document_type', 'sales_invoice')->firstOrFail();
        $this->post(route('paper.whatsapp', $share), ['template_id' => $invoice->id, 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertSessionHasErrors(['template_id' => __('core.print.wa_template_gone')]);

        $voucherText = PartyMessageTemplate::query()->where('document_type', self::KIND)->firstOrFail();
        $voucherText->forceFill(['is_active' => false])->save();
        $this->post(route('paper.whatsapp', $share), ['template_id' => $voucherText->id, 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertSessionHasErrors(['template_id' => __('core.print.wa_template_gone')]);

        $this->assertSame(0, PartyMessage::query()->count());
    }

    public function test_someone_without_the_papers_key_cannot_send_it(): void
    {
        $share = $this->share();
        $chosen = app(PartyMessages::class)->box($share->id)['choices'][0];

        $stranger = User::factory()->create([
            'current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id, 'is_active' => true,
        ]);
        $stranger->companies()->attach($this->company->id, ['is_active' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($stranger)
            ->post(route('paper.whatsapp', $share), ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertForbidden();

        $this->assertSame(0, PartyMessage::query()->count());
    }

    public function test_a_revoked_link_is_not_sent(): void
    {
        $share = $this->share();
        $chosen = app(PartyMessages::class)->box($share->id)['choices'][0];
        $share->forceFill(['revoked_at' => now()])->save();

        $this->post(route('paper.whatsapp', $share), ['template_id' => $chosen['id'], 'locale' => 'bn', 'phone' => '01712345678'])
            ->assertStatus(410);

        $this->assertNull(app(PartyMessages::class)->box($share->id), 'মরা লিংকের জন্যও বাক্সে WhatsApp-এর ফর্ম এসেছে।');
        $this->assertSame(0, PartyMessage::query()->count());
    }

    public function test_numbers_are_read_the_way_people_write_them(): void
    {
        $this->assertSame('8801712345678', PartyMessages::phone('01712345678'));
        $this->assertSame('8801712345678', PartyMessages::phone('+880 1712-345678'));
        $this->assertSame('8801712345678', PartyMessages::phone('008801712345678'));
        $this->assertSame('8801712345678', PartyMessages::phone('০১৭১২৩৪৫৬৭৮'), 'বাংলা অঙ্কে লেখা নম্বর চেনা যায়নি।');
        $this->assertSame('447911123456', PartyMessages::phone('+44 7911 123456'), 'বিদেশের নম্বর চেনা যায়নি।');
        $this->assertNull(PartyMessages::phone('02-9123456'), 'ল্যান্ডফোনকে মোবাইল ধরা হয়েছে।');
        $this->assertNull(PartyMessages::phone('01212345678'), '012 দিয়ে কোনো মোবাইল নেই।');
        $this->assertNull(PartyMessages::phone('88012345'));
        $this->assertNull(PartyMessages::phone(''));
        $this->assertNull(PartyMessages::phone(null));
    }

    public function test_the_texts_are_changed_from_the_control_panel_and_unknown_names_are_refused(): void
    {
        $this->get(route('notification.party_templates.index'))->assertOk()->assertSee('পরিশোধের খবর');
        $this->assertSame(count(PartyMessageTemplate::STARTERS), PartyMessageTemplate::query()->count(), 'শুরুর লেখাগুলো বসেনি।');

        $this->post(route('notification.party_templates.store'), [
            'name' => 'ভুল', 'channel' => 'whatsapp', 'body_bn' => 'প্রিয় {customer}', 'body_en' => 'Dear {party}', 'is_active' => '1',
        ])->assertSessionHasErrors('body_bn');

        $this->post(route('notification.party_templates.store'), [
            'name' => 'বকেয়ার কথা', 'channel' => 'whatsapp', 'document_type' => self::KIND,
            'body_bn' => "প্রিয় {party},\nবকেয়া ৳{amount}", 'body_en' => 'Dear {party}, due Tk {amount}', 'sort' => '5', 'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $made = PartyMessageTemplate::query()->where('name', 'বকেয়ার কথা')->sole();
        $this->assertSame("প্রিয় {party},\nবকেয়া ৳{amount}", $made->body_bn);

        $box = app(PartyMessages::class)->box($this->share()->id);
        $this->assertSame('বকেয়ার কথা', $box['choices'][0]['name'], 'নতুন লেখাটা বাক্সে আসেনি, বা ক্রম মানা হয়নি।');

        $this->put(route('notification.party_templates.update', $made), [
            'name' => 'বকেয়ার কথা', 'channel' => 'whatsapp', 'document_type' => self::KIND,
            'body_bn' => 'x', 'body_en' => 'x', 'sort' => '5', 'is_active' => '0',
        ])->assertRedirect();

        $box = app(PartyMessages::class)->box($this->share()->id);
        $this->assertNotContains('বকেয়ার কথা', array_column($box['choices'], 'name'), 'বন্ধ লেখাও বাক্সে আসছে।');
    }

    public function test_switching_every_text_off_does_not_bring_the_starters_back(): void
    {
        $this->get(route('notification.party_templates.index'))->assertOk();
        PartyMessageTemplate::query()->update(['is_active' => false]);

        $box = app(PartyMessages::class)->box($this->share()->id);

        $this->assertSame([], $box['choices'], 'মালিক সব লেখা বন্ধ করার পরেও শুরুর লেখা ফিরে এসেছে।');
        $this->assertSame(count(PartyMessageTemplate::STARTERS), PartyMessageTemplate::query()->count());
    }

    private function share(): DocumentShare
    {
        $existing = DocumentShare::query()->where('document_type', self::KIND)->where('document_id', $this->voucher->id)->latest('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->post(route('paper.share'), [
            'route' => 'accounts.voucher.print',
            'params' => ['voucher' => $this->voucher->id],
            'document_type' => self::KIND,
            'document_id' => $this->voucher->id,
            'paper' => PaperSize::A4,
        ])->assertRedirect()->assertSessionHas('shared_share');

        return DocumentShare::query()->latest('id')->firstOrFail();
    }

    private function voucherFor(Customer $customer): Voucher
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

        $voucher = $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'whatsapp test'],
            [
                ['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'],
                ['account_id' => $capital->id, 'debit' => '0', 'credit' => '1000'],
            ],
        ));

        // ⓘ পার্টি কাগজের মাথায় — ছাপা আর পাঠানো দুইটাই এখান থেকে পড়ে ([[PaperParties::keyOf()]])
        $voucher->forceFill(['party_type' => 'customer', 'party_id' => $customer->id])->saveQuietly();

        return $voucher->fresh();
    }
}
