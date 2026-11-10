<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Notifications\Channels\PhoneSmsGateway;
use App\Core\Notifications\Channels\SmsChannel;
use App\Core\Services\SmsOutboxService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentShare;
use App\Models\NotificationChannel;
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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ নিজের সিমে SMS — অফিসের Android ফোন সারি থেকে নেয়, পাঠায়, খবর দেয় (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২)।
 *
 * দাবি: চাবি একবারই দেখা যায় আর কেবল নিজের কোম্পানির সারি খোলে; একই SMS দুইবার নেওয়া যায় না; দিনের সীমা মানে; নিয়ে চুপ থাকলে
 * আবার সারিতে, তিনবারে "যায়নি"; অন্য ফোনের SMS-এর খবর গোনা হয় না; ফোন না থাকলে SMS-এর মাধ্যম "সংযুক্ত নয়"; কাগজের বাক্স থেকে
 * SMS — একই লেখা, খাতায় SMS, কেবল বাংলাদেশের মোবাইলে।
 */
final class AnSmsHadNoWayOutOfTheOfficeTest extends TestCase
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
    }

    /** ⭐ চাবি একবারই, তারপর সারি থেকে নেওয়া — দ্বিতীয়বার একই SMS নয়; ভুল চাবিতে ৪০১ */
    public function test_the_phone_takes_each_waiting_sms_once_with_its_own_key(): void
    {
        $token = $this->newPhone();
        $one = app(SmsOutboxService::class)->queue('8801711000001', 'প্রথম', SmsOutbox::PARTY);
        app(SmsOutboxService::class)->queue('8801711000002', 'দ্বিতীয়', SmsOutbox::PARTY);

        $taken = $this->take($token)->assertOk()->json('messages');
        $this->assertSame(['+8801711000001', '+8801711000002'], array_column($taken, 'phone'), '⛔ পুরনোটা আগে, দুইটাই নয়।');
        $this->assertSame((string) $one->public_id, $taken[0]['id']);
        $this->assertSame([], $this->take($token)->assertOk()->json('messages'), '⛔ একই SMS দুইবার নেওয়া গেল।');
        $this->take('smsgw_'.str_repeat('x', 48))->assertStatus(401);

        // ⓘ চাবি একবারই — পরের পাতায় দেখা যায়, তার পরের বার আর নয়
        $this->get(route('notification.sms_phones.index'))->assertOk()->assertSee($token, false);
        $this->get(route('notification.sms_phones.index'))->assertOk()->assertDontSee($token, false);
    }

    /** ⛔ এক কোম্পানির চাবি আরেক কোম্পানির সারি খোলে না */
    public function test_a_key_opens_only_its_own_companys_queue(): void
    {
        $token = $this->newPhone();
        $other = Company::query()->whereKeyNot($this->company->id)->firstOrFail();

        CompanyContext::forCompany((int) $other->id, fn () => app(SmsOutboxService::class)->queue('8801711000009', 'অন্যের', SmsOutbox::PARTY));

        $this->assertSame([], $this->take($token)->assertOk()->json('messages'), '⛔ অন্য কোম্পানির SMS এই ফোনে এল।');
    }

    /** ⭐ দিনের সীমা — সীমা ২ হলে দুইটার বেশি নয় */
    public function test_the_daily_limit_holds(): void
    {
        $token = $this->newPhone(limit: 2);

        foreach (range(1, 3) as $i) {
            app(SmsOutboxService::class)->queue('88017110000'.$i.$i, 'সীমা '.$i, SmsOutbox::PARTY);
        }

        $this->assertCount(2, $this->take($token)->json('messages'));
        $this->assertSame([], $this->take($token)->json('messages'), '⛔ দিনের সীমা পেরিয়ে আরও দিল।');
    }

    /** ⭐ খবর — গেছে, পৌঁছেছে, যায়নি; অন্য ফোনের নেওয়া SMS-এর খবর গোনা হয় না */
    public function test_reports_change_only_the_phones_own_rows(): void
    {
        $mine = $this->newPhone();
        $theirs = $this->newPhone('দ্বিতীয় ফোন');
        app(SmsOutboxService::class)->queue('8801711000001', 'এক', SmsOutbox::PARTY);
        app(SmsOutboxService::class)->queue('8801711000002', 'দুই', SmsOutbox::PARTY);
        [$a, $b] = array_column($this->take($mine)->json('messages'), 'id');

        $this->postJson(route('api.sms-gateway.report'), ['events' => [
            ['id' => $a, 'event' => 'sent'], ['id' => $a, 'event' => 'delivered'], ['id' => $b, 'event' => 'failed', 'reason' => 'no balance'],
        ]], ['Authorization' => 'Bearer '.$mine])->assertOk()->assertJson(['accepted' => 3]);

        $this->assertNotNull(SmsOutbox::query()->where('public_id', $a)->value('delivered_at'), '⛔ পৌঁছানো ওঠেনি।');
        $this->assertSame(SmsOutbox::FAILED, SmsOutbox::query()->where('public_id', $b)->value('status'));
        $this->assertSame('no balance', SmsOutbox::query()->where('public_id', $b)->value('error'));

        $this->postJson(route('api.sms-gateway.report'), ['events' => [['id' => $a, 'event' => 'failed']]], ['Authorization' => 'Bearer '.$theirs])
            ->assertOk()->assertJson(['accepted' => 0]);
        $this->assertSame(SmsOutbox::SENT, SmsOutbox::query()->where('public_id', $a)->value('status'), '⛔ অন্য ফোন এই ফোনের SMS-কে "যায়নি" করল।');
    }

    /** ⭐ নিয়ে চুপ — ১০ মিনিট পরে আবার সারিতে; তিনবারে "যায়নি" */
    public function test_a_silent_take_goes_back_and_the_third_time_fails(): void
    {
        $token = $this->newPhone();
        $row = app(SmsOutboxService::class)->queue('8801711000001', 'চুপ', SmsOutbox::PARTY);

        foreach (range(1, SmsOutbox::MAX_ATTEMPTS) as $attempt) {
            Carbon::setTestNow(now()->addMinutes(SmsOutbox::TAKEN_MINUTES + 1));
            $got = $this->take($token)->json('messages');
            $this->assertCount(1, $got, 'চেষ্টা '.$attempt.': ⛔ চুপ থাকা SMS আবার সারিতে আসেনি।');
        }

        Carbon::setTestNow(now()->addMinutes(SmsOutbox::TAKEN_MINUTES + 1));
        $this->assertSame([], $this->take($token)->json('messages'));
        $this->assertSame(SmsOutbox::FAILED, $row->fresh()->status, '⛔ তিনবার নিয়েও চুপ — তবু "যায়নি" হয়নি।');
        Carbon::setTestNow();
    }

    /** ⛔ ফোন না থাকলে বা বন্ধ করলে SMS-এর মাধ্যম "সংযুক্ত নয়", আর বিজ্ঞপ্তির SMS সারিতে জমে না */
    public function test_without_a_phone_the_sms_channel_is_not_connected(): void
    {
        $config = new NotificationChannel(['provider' => PhoneSmsGateway::NAME, 'enabled' => true, 'sender_id' => 'ABOS']);
        $this->assertFalse(app(SmsChannel::class)->connected($config), '⛔ ফোন ছাড়াই SMS "সংযুক্ত"।');
        $this->assertFalse(app(PhoneSmsGateway::class)->send('01711000001', 'x', 'ABOS', [])->ok());
        $this->assertSame(0, SmsOutbox::query()->count());

        $this->newPhone();
        $this->assertTrue(app(SmsChannel::class)->connected($config));
        $sent = app(PhoneSmsGateway::class)->send('01711000001', 'বিজ্ঞপ্তি', 'ABOS', []);
        $this->assertTrue($sent->ok());
        $this->assertSame('phone:'.SmsOutbox::query()->value('public_id'), $sent->providerRef);
    }

    /** ⭐ কাগজের বাক্স থেকে SMS — একই লেখা, খাতায় SMS; বিদেশের নম্বর নয় */
    public function test_the_paper_box_queues_the_same_text_as_sms_and_logs_it(): void
    {
        $this->newPhone();
        app(StandardChart::class)->install();
        $customer = Customer::query()->create(['company_id' => $this->company->id, 'code' => 'SMS-1', 'name_en' => 'Rahim', 'name_bn' => 'রহিম', 'phone' => '01712-345678', 'is_active' => true]);
        $share = $this->shareFor($customer);
        PartyMessageTemplate::ensureStarters((int) $this->company->id);
        $template = PartyMessageTemplate::query()->usableFor(PartyMessageTemplate::WHATSAPP, 'accounts_voucher')->firstOrFail();

        $this->post(route('paper.sms', $share), ['template_id' => $template->id, 'locale' => 'bn', 'phone' => '01712-345678'])
            ->assertRedirect()->assertSessionHas('sms_queued');

        $log = PartyMessage::query()->latest('id')->firstOrFail();
        $this->assertSame(PartyMessageTemplate::SMS, $log->channel);
        $this->assertSame($log->body, SmsOutbox::query()->latest('id')->value('body'), '⛔ খাতার লেখা আর SMS-এর লেখা আলাদা।');

        $this->post(route('paper.sms', $share), ['template_id' => $template->id, 'locale' => 'bn', 'phone' => '+44 7700 900123'])
            ->assertRedirect()->assertSessionHas('sms_error');
        $this->assertSame(1, SmsOutbox::query()->count(), '⛔ বিদেশের নম্বরে SMS সারিতে বসল।');
    }

    private function newPhone(string $name = 'অফিসের ফোন', int $limit = 200): string
    {
        $this->post(route('notification.sms_phones.store'), ['name' => $name, 'sim_number' => '01711000000', 'daily_limit' => $limit])
            ->assertRedirect()->assertSessionHas('sms_phone_token');

        return (string) session('sms_phone_token')['token'];
    }

    private function take(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('api.sms-gateway.take'), [], ['Authorization' => 'Bearer '.$token]);
    }

    private function shareFor(Customer $customer): DocumentShare
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $voucher = $vouchers->post($vouchers->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'sms test'], [
            ['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'], ['account_id' => $capital->id, 'debit' => '0', 'credit' => '1000'],
        ]));
        $voucher->forceFill(['party_type' => 'customer', 'party_id' => $customer->id])->saveQuietly();

        $this->post(route('paper.share'), ['route' => 'accounts.voucher.print', 'params' => ['voucher' => $voucher->id],
            'document_type' => 'accounts_voucher', 'document_id' => $voucher->id, 'paper' => PaperSize::A4])->assertRedirect();

        return DocumentShare::query()->latest('id')->firstOrFail();
    }
}
