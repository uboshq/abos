<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\PartyMessages;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\DocumentShare;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
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
use Tests\TestCase;

/**
 * ⭐ পার্টিকে বার্তা — লেখার পাতা, ধাপ ৩ (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব খ)।
 *
 * দাবি: {due} পার্টির এখনকার মোট বাকি বসায় (খাতার একই ভিত্তি); লেখা বদলালে Meta-র অনুমোদনের দাগ মুছে যায়, অন্য কিছু বদলালে নয়;
 * ট্যাব (সব / WhatsApp / SMS / বন্ধ) তালিকা ছাঁকে; SMS-এর লেখা রাখা যায়, "পার্টির হিসাব"-এর লেখাও; নতুন তিনটা শুরুর লেখা
 * আসে; পাতায় ব্যাখ্যার লাইন নেই।
 */
final class PartyTemplatesPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
    }

    public function test_due_is_the_partys_whole_balance_now(): void
    {
        $customer = Customer::query()->create([
            'company_id' => $this->company->id, 'code' => 'DUE-1', 'name_en' => 'Satthi Traders', 'name_bn' => 'সাথী ট্রেডার্স',
            'phone' => '01912-778104', 'is_active' => true,
        ]);

        // ⓘ বিল ১,১৮,২০০, জমা ৬০,০০০ → বাকি ৫৮,২০০
        $this->ledger($customer, '118200', '0');
        $this->ledger($customer, '0', '60000');

        $template = PartyMessageTemplate::query()->create([
            'company_id' => $this->company->id, 'channel' => PartyMessageTemplate::SMS, 'document_type' => PartyMessage::PARTY_ACCOUNT,
            'name' => 'বাকি পরীক্ষা', 'body_bn' => 'প্রিয় {party}, বাকি এখন ৳{due}', 'body_en' => 'Dear {party}, due Tk {due}',
            'is_active' => true, 'sort' => 1,
        ]);

        $this->newPhone();
        $share = $this->shareFor($customer);
        $box = app(PartyMessages::class)->box($share->id);

        $chosen = collect($box['sms_choices'])->firstWhere('id', $template->id);
        $this->assertNotNull($chosen, '⛔ পার্টির হিসাবের SMS লেখা কাগজের পপআপে নেই।');
        $this->assertSame('প্রিয় সাথী ট্রেডার্স, বাকি এখন ৳'.Money::format('58200'), $chosen['bn'], '⛔ {due} পার্টির মোট বাকি বসায়নি।');
        $this->assertSame('Dear Satthi Traders, due Tk '.Money::format('58200'), $chosen['en']);
        $this->assertSame('58200.0000', app(PartyMessages::class)->dueOf('customer', (int) $customer->id));

        // ⓘ অগ্রিম (জমা বেশি) — বাকি শূন্য, ঋণাত্মক নয়
        $this->ledger($customer, '0', '100000');
        $this->assertSame('0', app(PartyMessages::class)->dueOf('customer', (int) $customer->id));

        $this->assertContains('due', PartyMessageTemplate::VARIABLES);
        $this->assertSame([], PartyMessageTemplate::unknownIn('বাকি ৳{due}'), '⛔ {due} লেখায় রাখা যায় না।');
    }

    public function test_changing_the_text_clears_metas_approval_and_nothing_else_does(): void
    {
        PartyMessageTemplate::ensureStarters((int) $this->company->id);
        $template = PartyMessageTemplate::query()->where('name', 'বিক্রয় বিল')->firstOrFail();
        $template->forceFill(['wa_name' => 'abos_sales_invoice', 'wa_status' => 'approved'])->save();

        $form = fn (array $over = []): array => $over + [
            'name' => $template->name, 'channel' => PartyMessageTemplate::WHATSAPP, 'document_type' => 'sales_invoice',
            'body_bn' => $template->body_bn, 'body_en' => $template->body_en, 'sort' => (string) $template->sort, 'is_active' => '1',
        ];

        // ⓘ কেবল নাম বদল — অনুমোদন থাকে
        $this->put(route('notification.party_templates.update', $template), $form(['name' => 'বিক্রয় বিল (নতুন নাম)']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $template->fresh()->wa_status, '⛔ লেখা না বদলেও Meta-র অনুমোদন মুছে গেছে।');

        $this->put(route('notification.party_templates.update', $template), $form(['body_en' => 'Dear {party}, invoice {paper_no}: {link}']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($template->fresh()->wa_status, '⛔ লেখা বদলেছে, অথচ পুরনো লেখার Meta-অনুমোদন রয়ে গেছে।');
        $this->assertSame('abos_sales_invoice', $template->fresh()->wa_name);
    }

    public function test_the_tabs_filter_the_list(): void
    {
        $this->get(route('notification.party_templates.index'))->assertOk();
        $off = PartyMessageTemplate::query()->where('name', 'সাধারণ — যেকোনো কাগজ')->firstOrFail();
        $off->forceFill(['is_active' => false])->save();
        $sms = PartyMessageTemplate::query()->where('name', 'বাকির তাগাদা')->firstOrFail();
        $wa = PartyMessageTemplate::query()->where('name', 'বিক্রয় বিল')->firstOrFail();

        $row = fn (PartyMessageTemplate $t): string => 'data-party-template="'.$t->id.'"';

        $all = $this->get(route('notification.party_templates.index'))->assertOk();
        $all->assertSee($row($off), false)->assertSee($row($sms), false)->assertSee($row($wa), false);

        $this->get(route('notification.party_templates.index', ['tab' => 'sms']))->assertOk()
            ->assertSee($row($sms), false)->assertDontSee($row($wa), false)->assertDontSee($row($off), false);

        $this->get(route('notification.party_templates.index', ['tab' => 'whatsapp']))->assertOk()
            ->assertSee($row($wa), false)->assertSee($row($off), false)->assertDontSee($row($sms), false);

        $this->get(route('notification.party_templates.index', ['tab' => 'off']))->assertOk()
            ->assertSee($row($off), false)->assertDontSee($row($wa), false)->assertDontSee($row($sms), false);

        // ⓘ অচেনা ট্যাব = সব
        $this->get(route('notification.party_templates.index', ['tab' => 'nonsense']))->assertOk()->assertSee($row($sms), false);
    }

    public function test_the_page_has_the_new_starters_the_editor_and_no_description_line(): void
    {
        $html = $this->get(route('notification.party_templates.index'))->assertOk()->getContent();

        foreach (['বাকির তাগাদা', 'হিসাব বিবরণী', 'টাকা পেয়েছি (ছোট)'] as $name) {
            $this->assertStringContainsString($name, $html, '⛔ নতুন শুরুর লেখা "'.$name.'" নেই।');
        }

        $this->assertSame(PartyMessageTemplate::SMS, PartyMessageTemplate::query()->where('name', 'বাকির তাগাদা')->value('channel'));
        $this->assertSame(PartyMessage::PARTY_ACCOUNT, PartyMessageTemplate::query()->where('name', 'হিসাব বিবরণী')->value('document_type'));
        $this->assertSame('sales_collection', PartyMessageTemplate::query()->where('name', 'টাকা পেয়েছি (ছোট)')->value('document_type'),
            '⛔ ছোট রসিদের লেখা বড় রসিদের লেখার কাগজে নেই।');

        $this->assertStringContainsString('data-template-editor', $html, '⛔ তালিকার পাশে সম্পাদক নেই।');
        $this->assertStringContainsString('data-var="due"', $html, '⛔ "বাকি টাকা" চিপ নেই।');
        $this->assertStringContainsString('data-list-tabs', $html);
        $this->assertStringNotContainsString('data-page-subtitle', $html, '⛔ শিরোনামের নিচে ব্যাখ্যার লাইন ফিরে এসেছে।');
    }

    public function test_an_sms_text_and_a_party_account_text_can_be_saved(): void
    {
        $this->post(route('notification.party_templates.store'), [
            'name' => 'তাগাদা ২', 'channel' => PartyMessageTemplate::SMS, 'document_type' => PartyMessage::PARTY_ACCOUNT,
            'body_bn' => 'প্রিয় {party}, বাকি ৳{due}', 'body_en' => 'Dear {party}, due Tk {due}', 'is_active' => '1', 'tab' => 'sms',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $made = PartyMessageTemplate::query()->where('name', 'তাগাদা ২')->sole();
        $this->assertSame(PartyMessageTemplate::SMS, $made->channel);
        $this->assertSame(PartyMessage::PARTY_ACCOUNT, $made->document_type);

        $this->post(route('notification.party_templates.store'), [
            'name' => 'ভুল পথ', 'channel' => 'email', 'body_bn' => 'x', 'body_en' => 'x', 'is_active' => '1',
        ])->assertSessionHasErrors('channel');
    }

    private function ledger(Customer $customer, string $debit, string $credit): void
    {
        LedgerEntry::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->value('id'),
            'account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
            'source_type' => 'collection', 'source_id' => random_int(1000, 999999), 'trx_date' => now()->toDateString(),
            'party_type' => 'customer', 'party_id' => $customer->id, 'debit' => $debit, 'credit' => $credit, 'narration' => 'due test',
        ]);
    }

    private function newPhone(): void
    {
        $this->post(route('notification.sms_phones.store'), ['name' => 'অফিসের ফোন ১', 'sim_number' => '01711000000', 'daily_limit' => 200])
            ->assertRedirect()->assertSessionHas('sms_phone_token');
    }

    private function shareFor(Customer $customer): DocumentShare
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $voucher = $vouchers->post($vouchers->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'due text'], [
            ['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'], ['account_id' => $capital->id, 'debit' => '0', 'credit' => '1000'],
        ]));
        $voucher->forceFill(['party_type' => 'customer', 'party_id' => $customer->id])->saveQuietly();

        $this->post(route('paper.share'), ['route' => 'accounts.voucher.print', 'params' => ['voucher' => $voucher->id],
            'document_type' => 'accounts_voucher', 'document_id' => $voucher->id, 'paper' => PaperSize::A4])->assertRedirect();

        return DocumentShare::query()->latest('id')->firstOrFail();
    }
}
