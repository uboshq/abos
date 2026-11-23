<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\PartyAutoSend;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Jobs\SendPartyPaymentNews;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\PartyMessage;
use App\Models\PartyMessageOptout;
use App\Models\PartyMessageTemplate;
use App\Models\SmsGatewayPhone;
use App\Models\SmsOutbox;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ⭐ নিজে থেকে পাঠানো — বাকির তাগাদা আর টাকা পাওয়ার খবর (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব ঙ;
 * [[PartyAutoSend]], [[PartyReminders]], [[TellThePartyTheMoneyCame]])।
 *
 * দাবি: তাগাদা যায় কেবল মেয়াদ-পেরোনো (after_days) আর বাকি অন্তত min_due এমন পার্টিকে, বসানো সময়ের পরে, একবারই; যায় না — মেয়াদ
 * এখনো না পেরোলে, অঙ্ক কম হলে, বাদ রাখা হলে, শুক্রবারে (শনি–বৃহস্পতি বাছা থাকলে), রাতে (সকালে যায়), every_days-এর ভিতরে আবার।
 * রসিদ পাকা হলে "টাকা পেয়েছি" ঠিক মানে, একবারই; রাতে সকাল 9:00-এর সারিতে; "অনুমোদনের পরে" বাছা থাকলে অনুমোদনহীন রসিদে নয়।
 * সুইচ বন্ধ = কিছুই নয়। ⓘ পথ: অফিসের ফোন বসানো (Cloud API বসানো নেই) — তাই প্রতিটা বার্তা SMS-এর সারিতে।
 */
final class PartyAutoSendTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ সোমবার — শুক্রবার ২৭ নভেম্বর */
    private const MONDAY = '2026-11-23';

    private Company $company;

    private User $owner;

    private Customer $shop;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branchId = (int) $this->company->defaultBranch()?->id;
        CompanyContext::set($this->company->id, $this->branchId);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        PartyMessageTemplate::ensureStarters((int) $this->company->id);

        // ⓘ অফিসের ফোন — SMS-এর পথ খোলা ([[PartyMessageDispatcher::smsOn()]])
        SmsGatewayPhone::query()->create([
            'company_id' => $this->company->id, 'name' => 'অফিসের ফোন ১', 'token_hash' => hash('sha256', 'party-auto-test'),
            'daily_limit' => 500, 'is_active' => true,
        ]);

        $this->shop = $this->customer('AUTO-1', 'নিউ মদিনা স্টোর', 'New Madina Store', '01712-345678');

        $this->at(self::MONDAY.' 08:00');
    }

    // ═════════════════════════════════════════════════════════════════
    //  বাকির তাগাদা
    // ═════════════════════════════════════════════════════════════════

    public function test_the_due_run_reminds_an_overdue_party_once_after_its_time(): void
    {
        $this->dueOn();
        $bill = $this->overdueBill($this->shop, 'INV-AUTO-0431', daysLate: 10, amount: '18500');

        $this->at(self::MONDAY.' 09:55');
        $this->remind();
        $this->assertSame(0, $this->reminders(), '⛔ বসানো সময় (10:00) আসার আগেই তাগাদা গেছে।');

        $this->at(self::MONDAY.' 10:05');
        $this->remind();

        $row = PartyMessage::query()->where('rule', PartyMessage::DUE_REMINDER)->sole();
        $this->assertSame(PartyMessage::AUTO, $row->via);
        $this->assertSame(PartyMessage::PARTY_ACCOUNT, $row->document_type);
        $this->assertSame((int) $this->shop->id, (int) $row->document_id, 'তাগাদার কাগজ = পার্টির হিসাব, পার্টির id।');
        $this->assertSame((int) $this->shop->id, (int) $row->party_id);
        $this->assertSame('8801712345678', $row->phone);
        $this->assertSame(PartyMessage::PHONE, $row->route, 'Cloud বসানো নেই — অফিসের ফোনের পথে যাওয়ার কথা।');
        $this->assertNotNull($row->sms_outbox_id);
        $this->assertSame($row->body, SmsOutbox::query()->findOrFail($row->sms_outbox_id)->body, 'খাতার লেখা আর SMS-এর লেখা আলাদা।');
        $this->assertStringContainsString('18,500', $row->body, '{due} বসেনি — মোট বাকি পক্ষের খাতা থেকে।');
        $this->assertStringContainsString((string) $bill->document_no, $row->body, '{paper_no} = সবচেয়ে পুরনো খোলা বিল।');
        $this->assertStringContainsString(Carbon::parse($bill->trx_date)->format('d-m-Y'), $row->body, '{date} = সেই বিলের দিন।');
        $this->assertStringNotContainsString('{', $row->body, 'লেখায় কোনো নাম না-বসানো রয়ে গেছে।');

        // ⛔ সূচি পাঁচ মিনিট পরপর চলে — একই দিনে আবার নয়
        $this->at(self::MONDAY.' 10:10');
        $this->remind();
        $this->at(self::MONDAY.' 16:00');
        $this->remind();
        $this->assertSame(1, $this->reminders(), '⛔ একই দিনে একই পার্টি দুইবার তাগাদা পেয়েছে।');
    }

    public function test_a_bill_not_yet_late_enough_gets_no_reminder(): void
    {
        $this->dueOn([PartyAutoSend::DUE_AFTER => 7]);
        $this->overdueBill($this->shop, 'INV-AUTO-0500', daysLate: 3, amount: '18500');

        $this->at(self::MONDAY.' 10:30');
        $this->remind();

        $this->assertSame(0, $this->reminders(), '⛔ মেয়াদ পেরিয়েছে মাত্র ৩ দিন, নিয়ম ৭ দিন — তবু তাগাদা গেছে।');
        $this->assertSame([], app(PartyAutoSend::class)->dueList(now()), '"আজ কারা পাবেন"-এ এখনো-না-পেরোনো পার্টি।');
    }

    public function test_a_due_below_the_minimum_gets_no_reminder(): void
    {
        $this->dueOn([PartyAutoSend::DUE_MIN => '5000']);
        $this->overdueBill($this->shop, 'INV-AUTO-0501', daysLate: 10, amount: '3000');

        $this->at(self::MONDAY.' 10:30');
        $this->remind();

        $this->assertSame(0, $this->reminders(), '⛔ বাকি ৳3,000, সীমা ৳5,000 — তবু তাগাদা গেছে।');
    }

    public function test_a_party_left_out_gets_nothing_unless_the_switch_is_off(): void
    {
        $this->dueOn();
        $this->overdueBill($this->shop, 'INV-AUTO-0502', daysLate: 10, amount: '18500');
        PartyMessageOptout::query()->create([
            'company_id' => $this->company->id, 'party_type' => 'customer', 'party_id' => $this->shop->id, 'party_name' => 'নিউ মদিনা স্টোর',
        ]);

        $this->at(self::MONDAY.' 10:30');
        $this->remind();
        $this->assertSame(0, $this->reminders(), '⛔ "বার্তা পাঠাবেন না" দাগানো পার্টি তাগাদা পেয়েছে।');

        $this->dueOn([PartyAutoSend::OPTOUT => false]);
        $this->remind();
        $this->assertSame(1, $this->reminders(), 'বাদ রাখার নিয়ম বন্ধ — তালিকার পার্টিও পাওয়ার কথা।');
    }

    public function test_friday_is_skipped_unless_every_day_is_chosen(): void
    {
        $this->dueOn([PartyAutoSend::DUE_DAYS => PartyAutoSend::SAT_THU]);
        $this->overdueBill($this->shop, 'INV-AUTO-0503', daysLate: 10, amount: '18500');

        $this->at('2026-11-27 10:30');
        $this->assertTrue(now()->isFriday());
        $this->remind();
        $this->assertSame(0, $this->reminders(), '⛔ শনি–বৃহস্পতি বাছা, অথচ শুক্রবারে তাগাদা গেছে।');

        $this->dueOn([PartyAutoSend::DUE_DAYS => PartyAutoSend::EVERY_DAY]);
        $this->remind();
        $this->assertSame(1, $this->reminders(), '"প্রতিদিন" বাছা — শুক্রবারেও যাওয়ার কথা।');
    }

    public function test_nothing_goes_at_night_and_it_goes_in_the_morning(): void
    {
        $this->dueOn([PartyAutoSend::DUE_TIME => '22:00', PartyAutoSend::QUIET => true]);
        $this->overdueBill($this->shop, 'INV-AUTO-0504', daysLate: 10, amount: '18500');

        $this->at(self::MONDAY.' 22:30');
        $this->remind();
        $this->assertSame(0, $this->reminders(), '⛔ রাত 9:00-এর পরে তাগাদা গেছে।');

        $this->at('2026-11-24 08:55');
        $this->remind();
        $this->assertSame(0, $this->reminders(), '⛔ সকাল 9:00-এর আগে তাগাদা গেছে।');

        $this->at('2026-11-24 09:05');
        $this->remind();
        $this->assertSame(1, $this->reminders(), 'রাতে আটকে থাকা তাগাদা সকালে যাওয়ার কথা।');
    }

    public function test_a_party_reminded_within_every_days_is_not_reminded_again(): void
    {
        $this->dueOn([PartyAutoSend::DUE_EVERY => 7]);
        $this->overdueBill($this->shop, 'INV-AUTO-0505', daysLate: 20, amount: '18500');

        $this->at(self::MONDAY.' 10:30');
        $this->remind();
        $this->assertSame(1, $this->reminders());

        // ⓘ ৩ দিন পরে — এখনো ৭ দিন হয়নি
        $this->at('2026-11-26 10:30');
        $this->remind();
        $this->assertSame(1, $this->reminders(), '⛔ ৭ দিনের ভিতরে আবার তাগাদা গেছে।');

        // ⓘ ৭ দিন পরে (পরের সোমবার) — আবার
        $this->at('2026-11-30 10:30');
        $this->remind();
        $this->assertSame(2, $this->reminders(), '৭ দিন পেরোনোর পরে আবার যাওয়ার কথা।');
    }

    public function test_a_party_who_already_got_a_message_today_waits_when_once_a_day_is_on(): void
    {
        $this->dueOn([PartyAutoSend::ONCE_A_DAY => true]);
        $this->overdueBill($this->shop, 'INV-AUTO-0506', daysLate: 10, amount: '18500');

        $this->at(self::MONDAY.' 09:00');
        PartyMessage::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId, 'channel' => 'sms', 'document_type' => 'accounts_voucher',
            'document_id' => 1, 'party_type' => 'customer', 'party_id' => $this->shop->id, 'phone' => '8801712345678', 'locale' => 'bn',
            'body' => 'আপনার টাকা পেয়েছি', 'status' => PartyMessage::SENT, 'via' => PartyMessage::AUTO, 'rule' => PartyMessage::PAYMENT_RECEIVED,
        ]);

        $this->at(self::MONDAY.' 10:30');
        $this->remind();
        $this->assertSame(0, $this->reminders(), '⛔ "দিনে একবার" চালু — আজ খবর পাওয়া পার্টি তাগাদাও পেয়েছে।');
    }

    public function test_the_switch_off_sends_no_reminder(): void
    {
        $this->dueOn([PartyAutoSend::DUE_ON => false]);
        $this->overdueBill($this->shop, 'INV-AUTO-0507', daysLate: 10, amount: '18500');

        $this->at(self::MONDAY.' 10:30');
        $this->remind();

        $this->assertSame(0, PartyMessage::query()->count(), '⛔ তাগাদার সুইচ বন্ধ, তবু কিছু গেছে।');
        $this->assertSame([], app(PartyAutoSend::class)->dueList(now()));
    }

    public function test_each_branch_may_keep_its_own_rules(): void
    {
        // ⓘ কোম্পানির নিয়ম বন্ধ, এই শাখার চালু — শাখার পার্টি পান
        $this->dueOn([PartyAutoSend::DUE_ON => false, PartyAutoSend::PER_BRANCH => true]);
        app(PartyAutoSend::class)->save($this->rules([PartyAutoSend::DUE_ON => true, PartyAutoSend::PER_BRANCH => true]), $this->branchId);
        $this->overdueBill($this->shop, 'INV-AUTO-0508', daysLate: 10, amount: '18500');

        $this->assertFalse(app(PartyAutoSend::class)->rules(null)[PartyAutoSend::DUE_ON], 'শাখার নিয়ম কোম্পানির নিয়ম বদলে দিয়েছে।');
        $this->assertTrue(app(PartyAutoSend::class)->rules($this->branchId)[PartyAutoSend::DUE_ON]);

        $this->at(self::MONDAY.' 10:30');
        $this->remind();
        $this->assertSame(1, $this->reminders(), 'শাখার নিজের নিয়ম চালু — শাখার পার্টি পাওয়ার কথা।');
    }

    // ═════════════════════════════════════════════════════════════════
    //  টাকা পাওয়ার খবর
    // ═════════════════════════════════════════════════════════════════

    public function test_a_posted_receipt_tells_the_party_once_with_its_own_values(): void
    {
        $this->paidOn();
        $this->putOnTheLedger($this->shop, '78200');

        $this->at(self::MONDAY.' 11:00');
        $voucher = $this->receipt($this->shop, '20000');

        $row = PartyMessage::query()->where('rule', PartyMessage::PAYMENT_RECEIVED)->sole();
        $this->assertSame(PartyMessage::AUTO, $row->via);
        $this->assertSame('accounts_voucher', $row->document_type);
        $this->assertSame((int) $voucher->id, (int) $row->document_id);
        $this->assertSame((int) $this->shop->id, (int) $row->party_id);
        $this->assertSame('8801712345678', $row->phone);
        $this->assertStringContainsString('20,000', $row->body, '{amount} বসেনি।');
        $this->assertStringContainsString((string) $voucher->document_no, $row->body, '{paper_no} = রসিদের নম্বর।');
        $this->assertStringContainsString(now()->format('d-m-Y'), $row->body, '{date} = রসিদের দিন।');
        $this->assertStringContainsString('58,200', $row->body, '{due} = রসিদের পরে মোট বাকি।');

        // ⛔ একই রসিদের খবর আবার চাইলেও একবারই
        app(PartyAutoSend::class)->receiptPosted($voucher->fresh(), 'accounts_voucher');
        app(PartyAutoSend::class)->sendPaymentNews($voucher->getMorphClass(), (int) $voucher->id, 'accounts_voucher');
        $this->assertSame(1, PartyMessage::query()->where('rule', PartyMessage::PAYMENT_RECEIVED)->count(), '⛔ একই রসিদে দুইবার খবর।');
    }

    public function test_the_payment_news_never_slows_the_posting_it_goes_to_the_queue(): void
    {
        Queue::fake();
        $this->paidOn();

        $this->at(self::MONDAY.' 11:00');
        $voucher = $this->receipt($this->shop, '20000');

        Queue::assertPushed(SendPartyPaymentNews::class, fn (SendPartyPaymentNews $job) => $job->documentId === (int) $voucher->id
            && $job->documentType === 'accounts_voucher' && $job->companyId === (int) $this->company->id && $job->delay === null);
        $this->assertSame(0, PartyMessage::query()->count(), 'খবর পোস্টের ভিতরেই পাঠানো হয়েছে — সারিতে যাওয়ার কথা।');
    }

    public function test_a_receipt_at_night_waits_for_the_morning(): void
    {
        Queue::fake();
        $this->paidOn([PartyAutoSend::QUIET => true]);

        $this->at(self::MONDAY.' 22:15');
        $this->receipt($this->shop, '20000');

        Queue::assertPushed(SendPartyPaymentNews::class, fn (SendPartyPaymentNews $job) => $job->delay instanceof \DateTimeInterface
            && Carbon::instance($job->delay)->format('Y-m-d H:i') === '2026-11-24 09:00');
    }

    public function test_below_the_minimum_or_waiting_for_approval_or_switched_off_sends_no_payment_news(): void
    {
        $this->paidOn([PartyAutoSend::PAID_MIN => '50000']);
        $this->at(self::MONDAY.' 11:00');
        $this->receipt($this->shop, '20000');
        $this->assertSame(0, PartyMessage::query()->count(), '⛔ ৳20,000, সীমা ৳50,000 — তবু খবর গেছে।');

        // ⓘ "অনুমোদনের পরে" — এই রসিদে অনুমোদন লাগেনি, তাই খবর নয়
        $this->paidOn([PartyAutoSend::PAID_WHEN => PartyAutoSend::APPROVED]);
        $this->receipt($this->shop, '20000');
        $this->assertSame(0, PartyMessage::query()->count(), '⛔ "অনুমোদনের পরে" বাছা, অনুমোদনহীন রসিদে খবর গেছে।');

        $this->paidOn([PartyAutoSend::PAID_ON => false]);
        $this->receipt($this->shop, '20000');
        $this->assertSame(0, PartyMessage::query()->count(), '⛔ খবরের সুইচ বন্ধ, তবু খবর গেছে।');
    }

    public function test_a_party_left_out_gets_no_payment_news(): void
    {
        $this->paidOn();
        PartyMessageOptout::query()->create(['company_id' => $this->company->id, 'party_type' => 'customer', 'party_id' => $this->shop->id]);

        $this->at(self::MONDAY.' 11:00');
        $this->receipt($this->shop, '20000');

        $this->assertSame(0, PartyMessage::query()->count(), '⛔ বাদ রাখা পার্টি "টাকা পেয়েছি" পেয়েছে।');
    }

    // ═════════════════════════════════════════════════════════════════
    //  পাতা
    // ═════════════════════════════════════════════════════════════════

    public function test_the_page_shows_the_rules_today_list_and_the_left_out_parties(): void
    {
        $this->dueOn();
        $this->overdueBill($this->shop, 'INV-AUTO-0600', daysLate: 10, amount: '18500');
        $this->at(self::MONDAY.' 08:30');

        $this->get(route('notification.party_auto.index'))->assertOk()
            ->assertSee('data-auto-rule="due"', false)->assertSee('data-auto-rule="paid"', false)
            ->assertSee('data-due-count>1<', false)
            ->assertDontSee('data-page-subtitle', false);

        $this->get(route('notification.party_auto.index', ['tab' => 'today']))->assertOk()
            ->assertSee('INV-AUTO-0600')->assertSee('01712345678');

        $this->post(route('notification.party_auto.optout.store'), ['party' => 'customer:'.$this->shop->id, 'reason' => 'চাননি'])
            ->assertRedirect();
        $optout = PartyMessageOptout::query()->sole();
        $this->assertSame((int) $this->shop->id, (int) $optout->party_id);

        $this->get(route('notification.party_auto.index', ['tab' => 'today']))->assertOk()->assertDontSee('INV-AUTO-0600');

        $this->delete(route('notification.party_auto.optout.destroy', $optout))->assertRedirect();
        $this->assertSame(0, PartyMessageOptout::query()->count());
    }

    public function test_the_page_answers_only_to_its_key(): void
    {
        $stranger = User::factory()->create([
            'current_company_id' => $this->company->id, 'current_branch_id' => $this->branchId, 'is_active' => true,
        ]);
        $stranger->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($stranger)->get(route('notification.party_auto.index'))->assertForbidden();
        $this->actingAs($stranger)->put(route('notification.party_auto.save'), $this->form())->assertForbidden();
    }

    public function test_saving_the_page_writes_the_rules(): void
    {
        $this->put(route('notification.party_auto.save'), $this->form([
            'due_enabled' => '1', 'due_after' => '15', 'due_every' => '3', 'due_min' => '7,500', 'due_time' => '11:30', 'due_days' => 'every',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $rules = app(PartyAutoSend::class)->rules(null);
        $this->assertTrue($rules[PartyAutoSend::DUE_ON]);
        $this->assertSame(15, $rules[PartyAutoSend::DUE_AFTER]);
        $this->assertSame(3, $rules[PartyAutoSend::DUE_EVERY]);
        $this->assertSame('7500.00', $rules[PartyAutoSend::DUE_MIN], '"7,500" কমাসহ লেখা টাকা পড়া যায়নি।');
        $this->assertSame('11:30', $rules[PartyAutoSend::DUE_TIME]);
        $this->assertSame(PartyAutoSend::EVERY_DAY, $rules[PartyAutoSend::DUE_DAYS]);

        $this->put(route('notification.party_auto.save'), $this->form(['due_time' => '25:00']))->assertSessionHasErrors('due_time');
        $this->put(route('notification.party_auto.save'), $this->form(['due_after' => '5']))->assertSessionHasErrors('due_after');
    }

    // ═════════════════════════════════════════════════════════════════
    //  সাহায্য
    // ═════════════════════════════════════════════════════════════════

    private function at(string $when): void
    {
        $this->travelTo(Carbon::parse($when));
    }

    private function remind(): void
    {
        $this->artisan('abos:party-reminders')->assertSuccessful();
        CompanyContext::set($this->company->id, $this->branchId);
    }

    private function reminders(): int
    {
        return PartyMessage::query()->where('rule', PartyMessage::DUE_REMINDER)->count();
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function rules(array $over = []): array
    {
        return array_merge(PartyAutoSend::RULE_DEFAULTS, PartyAutoSend::SHARED_DEFAULTS, $over);
    }

    /** @param  array<string, mixed>  $over */
    private function dueOn(array $over = []): void
    {
        // ⓘ $over আগে — PHP-র `+`-এ বাঁ দিকের চাবি জেতে; উল্টো ক্রমে "সুইচ বন্ধ" কখনো পৌঁছাত না
        app(PartyAutoSend::class)->save($this->rules($over + [PartyAutoSend::DUE_ON => true]), null);
    }

    /** @param  array<string, mixed>  $over */
    private function paidOn(array $over = []): void
    {
        app(PartyAutoSend::class)->save($this->rules($over + [PartyAutoSend::PAID_ON => true, PartyAutoSend::QUIET => true]), null);
    }

    /**
     * @param  array<string, string>  $over
     * @return array<string, string>
     */
    private function form(array $over = []): array
    {
        return array_merge([
            'due_enabled' => '0', 'due_after' => '7', 'due_every' => '7', 'due_min' => '5000', 'due_time' => '10:00', 'due_days' => 'sat_thu',
            'paid_enabled' => '0', 'paid_when' => 'posted', 'paid_min' => '0', 'paid_link' => '1',
            'quiet' => '1', 'once_a_day' => '1', 'optout' => '1', 'per_branch' => '0',
        ], $over);
    }

    private function customer(string $code, string $bn, string $en, string $phone): Customer
    {
        return Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId, 'code' => $code, 'name_en' => $en, 'name_bn' => $bn,
            'phone' => $phone, 'is_active' => true,
        ]);
    }

    /**
     * ⓘ বিল (খোলা বিলের তালিকা আর "মেয়াদ পেরোনো" প্রশ্নের জন্য) আর খাতায় সেই অঙ্ক (মোট বাকি — [[PartyMessages::dueOf()]])।
     */
    private function overdueBill(Customer $customer, string $no, int $daysLate, string $amount): SalesInvoice
    {
        $bill = SalesInvoice::query()->create([
            'branch_id' => $this->branchId, 'document_no' => $no, 'customer_id' => $customer->id,
            'trx_date' => now()->subDays($daysLate + 5)->toDateString(), 'due_on' => now()->subDays($daysLate)->toDateString(),
            'subtotal' => $amount, 'discount' => '0', 'tax' => '0', 'total' => $amount, 'status' => DocumentStatus::CONFIRMED,
        ]);

        $this->putOnTheLedger($customer, $amount);

        return $bill;
    }

    /** ⓘ সরাসরি খাতায় — এখানে পরীক্ষার বিষয় তাগাদা, পোস্টিংয়ের নিয়ম নয় ([[TheFigureOnThePhoneWouldHaveGoneStaleTest]]-এর একই কারণ) */
    private function putOnTheLedger(Customer $customer, string $debit): void
    {
        LedgerEntry::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => (int) FinancialYear::query()->orderBy('id')->firstOrFail()->id,
            'account_id' => $this->receivable()->id,
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'trx_date' => now()->toDateString(),
            'debit' => $debit,
            'credit' => '0',
            'source_type' => 'party_auto_test',
            'source_id' => $customer->id,
            'narration' => 'তাগাদার পরীক্ষা',
            'created_by' => $this->owner->id,
        ]);
    }

    private function receivable(): Account
    {
        return Account::query()->postable()->active()
            ->where(fn ($q) => $q->where('code', StandardChart::RECEIVABLE)
                ->orWhereIn('parent_id', Account::query()->where('code', StandardChart::RECEIVABLE)->select('id')))
            ->orderBy('code')->firstOrFail();
    }

    /** ⓘ আসল রসিদ (RCV), আসল পোস্ট — [[VoucherService::post()]] → `VoucherPosted` → [[TellThePartyTheMoneyCame]] */
    private function receipt(Customer $customer, string $amount): Voucher
    {
        $bank = Account::query()->where('code', '110299')->first() ?? tap(
            Account::query()->where('code', StandardChart::BANK)->firstOrFail()->replicate(['public_id']),
            function (Account $a): void {
                $a->forceFill(['code' => '110299', 'name_en' => 'Test Bank', 'name_bn' => 'পরীক্ষার ব্যাংক', 'is_group' => false,
                    'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'), 'money_kind' => Account::BANK])->save();
            });

        $service = app(VoucherService::class);
        $voucher = $service->create([
            'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'party_type' => 'customer', 'party_id' => $customer->id,
            'instrument' => 'transfer', 'instrument_no' => 'TRX-'.random_int(100000, 999999), 'narration' => 'আদায়',
        ], $service->twoLineEntry(Voucher::RECEIPT, (int) $this->receivable()->id, (int) $bank->id, $amount, null, null));

        return $service->post($voucher);
    }
}
