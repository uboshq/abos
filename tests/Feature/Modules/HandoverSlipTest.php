<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Print\PaperSize;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * হস্তান্তরের স্লিপ — দুইজনের সইয়ের কাগজ।
 *
 * ── কেন কাগজটা লাগে ─────────────────────────────────────────────────
 * ঝগড়াটা কখনো "টাকা দিয়েছি কি না" নিয়ে হয় না — হয় **কত** আর **কখন**
 * নিয়ে। যিনি নিচ্ছেন তাঁর হাতে পর্দা থাকে না, আর যিনি দিচ্ছেন তিনি
 * পরে রেকর্ড বদলাতে পারেন বলে সন্দেহ থেকে যায়।
 *
 * ── কাগজে যা যা দেখা হয় ─────────────────────────────────────────────
 * PDF-এর বাইনারিতে বাংলা লেখা সাবসেট করা ফন্টে বসে, তাই ওখানে খুঁজে
 * না পাওয়া কিছুই প্রমাণ করে না। তাই আসল রিকোয়েস্ট চালিয়ে তার ডেটা
 * ধরা হয়, আর সেই ডেটা দিয়ে টেমপ্লেটটাই HTML-এ রেন্ডার করে পড়া হয় —
 * যা কাগজে যায়, সেটাই।
 */
class HandoverSlipTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private CashTill $from;

    private CashTill $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $tills = app(CashTillService::class);
        $this->from = $tills->ensurePrimaryTill();
        $this->to = $tills->create([
            'code' => 'SAFE',
            'name_en' => 'Office Safe',
            'name_bn' => 'অফিসের সিন্দুক',
        ]);

        Account::query()->whereKey($this->from->account_id)->update([
            'opening_balance' => '20000',
            'opening_date' => '2026-07-01',
        ]);
        /*
         * কলামে বসানোই যথেষ্ট নয় — জেরটা খতিয়ানে বসাতে হয়।
         *
         * ── কেন এটা যোগ করতে হলো, ৩১ আগস্ট ২০২৬ ─────────────────────
         * খোলার জের এখন **খতিয়ানের প্রথম সারি**, আর
         * [[Account::balanceOn()]] কেবল খতিয়ান গোনে — `opening_balance`
         * কলামটা সে দেখেই না। কলামটা এখন কেবল **ঘোষণা**, ব্যালেন্স নয়।
         *
         * ওই বদলের পর এই সহায়কটা হালনাগাদ হয়নি, তাই টিলে টাকা বসত না
         * আর "হাতে আছে মাত্র ০.০০ টাকা" বলে সব হস্তান্তর আটকে যেত।
         * কোডের দোষ ছিল না; সহায়কটা বাসি ছিল।
         */
        app(OpeningBalanceService::class)->forAccount(
            Account::query()->findOrFail($this->from->account_id)
        );

    }

    private function handover(): MoneyTransfer
    {
        return app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->from->id,
            'to_till_id' => $this->to->id,
            'amount' => '12000',
            'trx_date' => now()->toDateString(),
            'narration' => 'দিনশেষে সিন্দুকে',
        ]);
    }

    /**
     * কাগজে সত্যিই যা ছাপা হয় — কন্ট্রোলার কী পাঠাল তা নয়।
     *
     * এই তফাতটা এই প্রকল্পে দুইবার কামড়েছে: `notice` ও `signatures`
     * দুইটাই বছরের পর বছর পাঠানো হচ্ছিল অথচ টেমপ্লেট ছাপত না, আর
     * পরীক্ষা সবুজ ছিল।
     */
    private function paperHtml(MoneyTransfer $transfer, string $paper = PaperSize::A4): string
    {
        $seen = [];
        View::composer('print.handover', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('accounts.transfer.print', $transfer).'?paper='.$paper)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertNotSame([], $seen, 'স্লিপের টেমপ্লেটটাই ডাকা হয়নি।');

        return view('print.handover', $seen)->render();
    }

    public function test_a_handover_can_be_printed(): void
    {
        $html = $this->paperHtml($this->handover());

        $this->assertStringContainsString(__('accounts::print.handover_title'), $html);
    }

    /**
     * দুইটা সইয়ের ঘর — যিনি দিলেন, যিনি পেলেন।
     *
     * একটা ঘর থাকলে কাগজটা এক পক্ষের কথাই বলত। দুইটা সই ছাড়া স্লিপটা
     * প্রমাণ নয়, শুধু একটা রসিদের ভান।
     */
    public function test_the_slip_has_a_line_for_both_hands(): void
    {
        $html = $this->paperHtml($this->handover());

        $this->assertStringContainsString(__('accounts::print.handover_given_by'), $html);
        $this->assertStringContainsString(__('accounts::print.handover_received_by'), $html);
    }

    /**
     * নামটাও ছাপা থাকে, শুধু ভূমিকা নয়।
     *
     * ছয় মাস পরে কাগজটা দেখে বোঝা যেতে হবে কার সই থাকার কথা ছিল।
     * "দাতা / গ্রহীতা" লেখা থাকলে কেউ অন্যের হয়ে সই করলেও মিলিয়ে দেখার
     * উপায় থাকত না।
     */
    public function test_the_slip_names_who_should_be_signing(): void
    {
        $this->assertStringContainsString(
            $this->owner->name,
            $this->paperHtml($this->handover()),
            'সইয়ের ঘরে কারও নাম ছাপা হয়নি — কাগজটা ছয় মাস পরে কিছুই বলবে না।',
        );
    }

    /**
     * A4-তে দুই কপি, একটা করে দুই পক্ষের জন্য।
     *
     * এক কপি দিলে যাঁর কাছে থাকল না তিনিই পরে প্রমাণহীন — আর সেটাই
     * ঠিক সেই মানুষটা যাঁর জন্য কাগজটা লাগে।
     */
    public function test_a4_carries_a_copy_for_each_side(): void
    {
        $html = $this->paperHtml($this->handover());

        $this->assertStringContainsString(__('accounts::print.handover_copy_giver'), $html);
        $this->assertStringContainsString(__('accounts::print.handover_copy_receiver'), $html);
        $this->assertStringContainsString(__('accounts::print.handover_cut_here'), $html);
    }

    /**
     * তাপীয় রোলে একটাই কপি।
     *
     * ৫৮মিমি রোল কেটে দুই টুকরো করা যায় না, আর ওখানে স্লিপটা সাধারণত
     * সাথে সাথেই হাতে যায়।
     */
    public function test_a_thermal_roll_prints_one_copy_only(): void
    {
        $html = $this->paperHtml($this->handover(), PaperSize::THERMAL_58);

        $this->assertStringNotContainsString(__('accounts::print.handover_cut_here'), $html);
        $this->assertStringContainsString(__('accounts::print.handover_copy_single'), $html);

        // সইয়ের ঘর দুইটাই থাকে — কাগজ ছোট হলেও কাজটা একই
        $this->assertStringContainsString(__('accounts::print.handover_given_by'), $html);
        $this->assertStringContainsString(__('accounts::print.handover_received_by'), $html);
    }

    /**
     * অঙ্কটা কথায়ও লেখা থাকে।
     *
     * সংখ্যায় একটা শূন্য বসিয়ে দেওয়া সহজ; কথায় লেখা থাকলে সেটা আর
     * নিঃশব্দে করা যায় না।
     */
    public function test_the_amount_is_written_in_words_too(): void
    {
        $seen = [];
        View::composer('print.handover', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('accounts.transfer.print', $this->handover()))->assertOk();

        $this->assertNotSame('', $seen['handover']['amount_in_words'] ?? '');
    }

    /** বাতিল করা হস্তান্তরের কাগজে "বাতিল" লেখা ওঠে। */
    public function test_a_cancelled_handover_says_so_on_the_paper(): void
    {
        $transfer = $this->handover();

        app(MoneyTransferService::class)->cancel($transfer, 'ভুল কাউন্টারে পাঠানো হয়েছিল');

        $this->assertStringContainsString(
            __('accounts::print.cancelled'),
            $this->paperHtml($transfer->fresh()),
            'বাতিল করা স্লিপ হুবহু বৈধ স্লিপের মতো দেখাচ্ছে — কেউ ওটা প্রমাণ হিসেবে দেখাতে পারবেন।',
        );
    }

    /** চালু হস্তান্তরে ওই ছাপ থাকে না। */
    public function test_a_live_handover_carries_no_cancelled_stamp(): void
    {
        $this->assertStringNotContainsString(
            __('accounts::print.cancelled'),
            $this->paperHtml($this->handover()),
        );
    }

    // ── কে ছাপতে পারে ───────────────────────────────────────────────

    public function test_someone_without_the_permission_cannot_print(): void
    {
        $transfer = $this->handover();

        // কোম্পানিতে আছেন, কিন্তু হস্তান্তরের অনুমতি নেই
        $reader = User::factory()->create();
        $reader->companies()->attach($this->company, ['is_active' => true]);
        $reader->forceFill(['current_company_id' => $this->company->id])->save();
        $reader->givePermissionTo(Permission::findOrCreate('accounts.view', 'web'));

        $this->actingAs($reader)
            ->get(route('accounts.transfer.print', $transfer))
            ->assertForbidden();
    }

    public function test_a_guest_gets_the_login_screen_not_a_slip(): void
    {
        $transfer = $this->handover();

        auth()->logout();

        $this->get(route('accounts.transfer.print', $transfer))->assertRedirect(route('login'));
    }
}
