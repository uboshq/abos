<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\DataScope;
use App\Core\Services\PartyMessageDispatcher;
use App\Core\Services\SettingsService;
use App\Core\Services\WhatsAppCloud;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\SmsGatewayPhone;
use App\Models\SmsOutbox;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ পার্টিকে বার্তা — খাতা: WhatsApp আর SMS এক তালিকায়, প্রতিটার অবস্থাসহ (ধাপ ৩, মালিকের "ok" ২৩ নভেম্বর ২০২৬; নমুনা
 * HUXqpfhxng8823eEFrvH54 ট্যাব গ)।
 *
 * দাবি: ট্যাব অবস্থা ধরে ছাঁকে; আজকের চার সংখ্যা ঠিক; Cloud-এর ব্যর্থতার পরে SMS গেলে লাল লাইনে তাই লেখা; "আবার পাঠান" আর
 * "অন্য পথে পাঠান" নতুন সারি বানায় পাঠানোর একমাত্র দরজা দিয়ে ([[PartyMessageDispatcher]]); বন্ধ পথ কারণসহ ফেরে; শাখার দেয়াল অটুট;
 * শিরোনামের নিচে ব্যাখ্যার লাইন নেই।
 */
final class PartyMessageLogTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Voucher $voucher;

    private PartyMessageTemplate $template;

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
            'company_id' => $this->company->id, 'code' => 'LOG-1', 'name_en' => 'Rahim Traders', 'name_bn' => 'রহিম ট্রেডার্স',
            'phone' => '01712-345678', 'is_active' => true,
        ]);
        $this->voucher = $this->voucherFor($this->customer);

        PartyMessageTemplate::ensureStarters((int) $this->company->id);
        $this->template = PartyMessageTemplate::query()->where('channel', PartyMessageTemplate::WHATSAPP)
            ->where('document_type', 'accounts_voucher')->firstOrFail();
    }

    public function test_the_tabs_filter_by_state_and_there_is_no_description_line(): void
    {
        $opened = $this->message(['status' => PartyMessage::OPENED]);
        $waiting = $this->message(['status' => PartyMessage::WAITING, 'channel' => 'sms', 'route' => PartyMessage::PHONE]);
        $sent = $this->message(['status' => PartyMessage::SENT, 'route' => PartyMessage::CLOUD, 'sent_at' => now()]);
        $delivered = $this->message(['status' => PartyMessage::DELIVERED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'delivered_at' => now()]);
        $read = $this->message(['status' => PartyMessage::READ, 'route' => PartyMessage::CLOUD, 'read_at' => now()]);
        $failed = $this->message(['status' => PartyMessage::FAILED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'error' => 'নম্বর ঠিক নয়']);

        $tab = fn (?string $t) => $this->get(route('notification.party_messages.index', $t === null ? [] : ['tab' => $t]))->assertOk();
        $sees = fn ($response, array $rows) => array_map(fn (PartyMessage $m) => $response->assertSee('data-party-message="'.$m->id.'"', false), $rows);
        $misses = fn ($response, array $rows) => array_map(fn (PartyMessage $m) => $response->assertDontSee('data-party-message="'.$m->id.'"', false), $rows);

        $all = $tab(null);
        $sees($all, [$opened, $waiting, $sent, $delivered, $read, $failed]);
        $all->assertSee(__('notification::party_log.title'))
            ->assertSee(__('notification::party_log.tabs.delivered'))
            ->assertDontSee(__('notification::party.messages_note'))
            ->assertSee(__('notification::party_log.states.waiting_phone'))
            ->assertSee(__('notification::party_log.states.opened'));

        $delivers = $tab('delivered');
        $sees($delivers, [$delivered, $read]);
        $misses($delivers, [$opened, $waiting, $sent, $failed]);

        $waits = $tab('waiting');
        $sees($waits, [$waiting, $sent]);
        $misses($waits, [$opened, $delivered, $read, $failed]);

        $fails = $tab('failed');
        $sees($fails, [$failed]);
        $misses($fails, [$opened, $waiting, $sent, $delivered, $read]);
        $fails->assertSee('নম্বর ঠিক নয়');

        // ⓘ অচেনা ট্যাব = সব, ভাঙে না
        $sees($tab('nonsense'), [$opened, $failed]);
    }

    public function test_todays_figures_count_only_today(): void
    {
        $this->message(['status' => PartyMessage::DELIVERED]);
        $this->message(['status' => PartyMessage::READ]);
        $this->message(['status' => PartyMessage::WAITING]);
        $this->message(['status' => PartyMessage::SENT]);
        $this->message(['status' => PartyMessage::FAILED, 'error' => 'x']);
        $this->message(['status' => PartyMessage::OPENED]);
        $this->message(['status' => PartyMessage::DELIVERED], Carbon::yesterday()->setTime(12, 0));

        $this->get(route('notification.party_messages.index'))->assertOk()
            ->assertViewHas('today', ['sent' => 6, 'delivered' => 2, 'waiting' => 2, 'failed' => 1]);
    }

    public function test_a_cloud_failure_that_fell_back_to_sms_says_so(): void
    {
        $cloud = $this->message(['status' => PartyMessage::FAILED, 'route' => PartyMessage::CLOUD, 'via' => PartyMessage::AUTO,
            'error' => 'এই নম্বরে WhatsApp নেই', 'sent_by' => null]);
        $sms = $this->message(['status' => PartyMessage::DELIVERED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'via' => PartyMessage::AUTO,
            'fallback_of_id' => $cloud->id, 'delivered_at' => now()->setTime(9, 59), 'sent_by' => null]);
        $alone = $this->message(['status' => PartyMessage::FAILED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'error' => 'সিম বন্ধ']);

        $this->get(route('notification.party_messages.index'))->assertOk()
            ->assertSee(__('notification::party_log.fell_back', ['reason' => 'এই নম্বরে WhatsApp নেই']))
            ->assertSee(__('notification::party_log.states.delivered', ['time' => '09:59']))
            ->assertSee(__('notification::party_log.auto', ['road' => __('notification::party_log.cloud')]))
            ->assertSee('সিম বন্ধ')
            ->assertDontSee(__('notification::party_log.fell_back', ['reason' => 'সিম বন্ধ']))
            ->assertSee('01712-345678');

        $this->assertNotNull($sms->id);
        $this->assertNotNull($alone->id);
    }

    public function test_send_again_takes_the_same_road_through_the_dispatcher_as_a_new_row(): void
    {
        $this->officePhone();
        $old = $this->message(['status' => PartyMessage::FAILED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'error' => 'সিম বন্ধ',
            'body' => 'পুরনো লেখা, মরা লিংক']);

        $this->from(route('notification.party_messages.index'))
            ->post(route('notification.party_messages.resend', $old))
            ->assertRedirect(route('notification.party_messages.index'))->assertSessionHas('saved');

        $new = PartyMessage::query()->where('id', '>', $old->id)->sole();
        $this->assertSame(PartyMessage::PHONE, $new->route, '⛔ আবার পাঠানো অন্য পথে গেল');
        $this->assertSame(PartyMessage::WAITING, $new->status);
        $this->assertSame(PartyMessage::BUTTON, $new->via);
        $this->assertSame((int) $this->owner->id, (int) $new->sent_by, '⛔ কে আবার পাঠালেন, খাতায় নেই');

        $queued = SmsOutbox::query()->where('source', SmsOutbox::PARTY)->where('source_id', $new->id)->sole();
        $this->assertSame((int) $queued->id, (int) $new->sms_outbox_id, '⛔ পাঠানোর দরজা (viaSms) দিয়ে যায়নি — সারির নম্বর খাতায় নেই');
        $this->assertStringNotContainsString('মরা লিংক', (string) $new->body, '⛔ পুরনো লেখা (মরা লিংকসহ) আবার গেল — কাগজের জীবিত লিংকে নতুন করে বানানোর কথা');
        $this->assertStringContainsString((string) $this->voucher->document_no, (string) $new->body);

        $this->assertSame(PartyMessage::FAILED, $old->fresh()->status, '⛔ পুরনো সারি বদলে গেছে — সে যা হয়েছিল তাই থাকার কথা');
    }

    public function test_send_again_on_a_wame_row_reopens_wame(): void
    {
        $old = $this->message(['status' => PartyMessage::OPENED]);

        $response = $this->post(route('notification.party_messages.resend', $old));

        $new = PartyMessage::query()->where('id', '>', $old->id)->sole();
        $response->assertRedirect('https://wa.me/8801712345678?text='.rawurlencode((string) $new->body));
        $this->assertSame(PartyMessage::WAME, $new->route);
        $this->assertSame(PartyMessage::OPENED, $new->status);
        $this->assertSame(0, SmsOutbox::query()->count());
    }

    public function test_the_other_road_turns_whatsapp_into_sms_and_sms_into_whatsapp(): void
    {
        $this->officePhone();

        // WhatsApp → SMS (অফিসের ফোন)
        $wa = $this->message(['status' => PartyMessage::OPENED]);
        $this->post(route('notification.party_messages.other_road', $wa))->assertRedirect()->assertSessionHas('saved');
        $sms = PartyMessage::query()->where('id', '>', $wa->id)->sole();
        $this->assertSame(PartyMessageTemplate::SMS, $sms->channel);
        $this->assertSame(PartyMessage::PHONE, $sms->route);
        $this->assertTrue(SmsOutbox::query()->where('source_id', $sms->id)->exists(), '⛔ অন্য পথ পাঠানোর দরজা দিয়ে যায়নি');

        // SMS → WhatsApp: Cloud তৈরি নয় → wa.me
        $response = $this->post(route('notification.party_messages.other_road', $sms));
        $wame = PartyMessage::query()->where('id', '>', $sms->id)->sole();
        $response->assertRedirect('https://wa.me/8801712345678?text='.rawurlencode((string) $wame->body));
        $this->assertSame(PartyMessage::WAME, $wame->route);

        // SMS → WhatsApp: Cloud তৈরি আর লেখা Meta-র অনুমোদিত → Cloud দিয়ে (viaCloud)
        $this->template->forceFill(['wa_name' => 'voucher_paid', 'wa_status' => WhatsAppCloud::APPROVED])->save();
        $sent = new \ArrayObject;
        $this->app->instance(WhatsAppCloud::class, new class($sent) extends WhatsAppCloud
        {
            public function __construct(private \ArrayObject $sent) {}

            public function ready(): bool
            {
                return true;
            }

            public function canSend(PartyMessageTemplate $template): bool
            {
                return $template->wa_status === self::APPROVED;
            }

            public function send(string $phone, PartyMessageTemplate $template, string $locale, array $values): array
            {
                $this->sent[] = $phone;

                return ['ok' => true, 'id' => 'wamid.TEST', 'error' => null];
            }
        });

        $this->post(route('notification.party_messages.other_road', $sms))->assertRedirect()->assertSessionHas('saved');
        $cloud = PartyMessage::query()->where('id', '>', $wame->id)->sole();
        $this->assertSame(PartyMessage::CLOUD, $cloud->route);
        $this->assertSame(PartyMessage::SENT, $cloud->status);
        $this->assertSame('wamid.TEST', $cloud->provider_ref);
        $this->assertSame(['8801712345678'], $sent->getArrayCopy(), '⛔ Cloud-এর পথে পাঠানো হয়নি');
    }

    public function test_a_closed_road_is_refused_with_its_reason_and_nothing_is_written(): void
    {
        // ⓘ অফিসের ফোন নেই — SMS-এর পথ বন্ধ
        $sms = $this->message(['status' => PartyMessage::FAILED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'error' => 'x']);
        $this->post(route('notification.party_messages.resend', $sms))
            ->assertRedirect()->assertSessionHas('failed', __('notification::party_log.road_off.sms'));

        // ⓘ wa.me বন্ধ
        app(SettingsService::class)->set(PartyMessageDispatcher::WAME_ON, false);
        $wa = $this->message(['status' => PartyMessage::OPENED]);
        $this->post(route('notification.party_messages.resend', $wa))
            ->assertRedirect()->assertSessionHas('failed', __('notification::party_log.road_off.whatsapp'));

        // ⓘ বিদেশের নম্বরে SMS নয়
        $this->officePhone();
        $abroad = $this->message(['status' => PartyMessage::OPENED, 'phone' => '447911123456']);
        $this->post(route('notification.party_messages.other_road', $abroad))
            ->assertRedirect()->assertSessionHas('failed', __('notification::party_log.road_off.sms_abroad'));

        $this->assertSame(3, PartyMessage::query()->count(), '⛔ বন্ধ পথেও খাতায় সারি পড়েছে');
        $this->assertSame(0, SmsOutbox::query()->count());
    }

    public function test_someone_without_the_log_key_or_the_papers_key_cannot_resend(): void
    {
        $this->officePhone();
        $row = $this->message(['status' => PartyMessage::FAILED, 'channel' => 'sms', 'route' => PartyMessage::PHONE, 'error' => 'x']);

        $stranger = $this->person([]);
        $this->actingAs($stranger)->get(route('notification.party_messages.index'))->assertForbidden();
        $this->actingAs($stranger)->post(route('notification.party_messages.resend', $row))->assertForbidden();

        // ⓘ খাতার চাবি আছে, কাগজ ছাপার চাবি নেই — কাগজের সারি আবার পাঠানো যায় না (প্রথমবার পাঠানোর একই অধিকার)
        $reader = $this->person(['notification.party_messages']);
        $this->actingAs($reader)->get(route('notification.party_messages.index'))->assertOk();
        $this->actingAs($reader)->post(route('notification.party_messages.resend', $row))->assertForbidden();

        $this->assertSame(1, PartyMessage::query()->count());
    }

    public function test_the_branch_wall_still_stands(): void
    {
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        $ours = $this->message(['branch_id' => $mms->id, 'party_name' => 'OURS-MMS-PARTY']);
        $theirs = $this->message(['branch_id' => $ntk->id, 'party_name' => 'THEIRS-NTK-PARTY']);

        $clerk = $this->person(['notification.party_messages'], $mms);
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(DataScope::class)->forget();

        $this->actingAs($clerk->fresh())->get(route('notification.party_messages.index'))->assertOk()
            ->assertSee('OURS-MMS-PARTY')->assertDontSee('THEIRS-NTK-PARTY');
        $this->actingAs($clerk->fresh())->get(route('notification.party_messages.show', $theirs))->assertNotFound();
        $this->actingAs($clerk->fresh())->post(route('notification.party_messages.resend', $theirs))->assertNotFound();
        $this->actingAs($clerk->fresh())->post(route('notification.party_messages.other_road', $theirs))->assertNotFound();
        $this->actingAs($clerk->fresh())->get(route('notification.party_messages.show', $ours))->assertOk()->assertSee('OURS-MMS-PARTY');

        $this->assertSame(2, PartyMessage::query()->withoutGlobalScopes()->count(), '⛔ অন্য শাখার সারি আবার পাঠানো হলো');
    }

    public function test_the_text_page_shows_exactly_what_went(): void
    {
        $row = $this->message(['body' => "প্রিয় রহিম ট্রেডার্স,\nআপনার কাগজ"]);

        $this->get(route('notification.party_messages.show', $row))->assertOk()
            ->assertSee('data-party-body', false)
            ->assertSee('আপনার কাগজ')
            ->assertSee($this->template->name);
    }

    /** @param  array<string, mixed>  $over */
    private function message(array $over = [], ?Carbon $at = null): PartyMessage
    {
        $row = PartyMessage::query()->forceCreate(array_merge([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'channel' => PartyMessageTemplate::WHATSAPP,
            'document_type' => 'accounts_voucher',
            'document_id' => $this->voucher->id,
            'document_no' => $this->voucher->document_no,
            'party_type' => 'customer',
            'party_id' => $this->customer->id,
            'party_name' => 'রহিম ট্রেডার্স',
            'phone' => '8801712345678',
            'locale' => 'bn',
            'template_id' => $this->template->id,
            'body' => 'লেখা',
            'sent_by' => $this->owner->id,
            'status' => PartyMessage::OPENED,
            'route' => PartyMessage::WAME,
            'via' => PartyMessage::BUTTON,
        ], $over));

        if ($at !== null) {
            $row->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $row->fresh();
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
        $user = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => ($branch ?? $this->company->defaultBranch())?->id,
            'is_active' => true,
        ]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        if ($keys !== []) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
                array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function voucherFor(Customer $customer): Voucher
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

        $voucher = $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'party log test'],
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
