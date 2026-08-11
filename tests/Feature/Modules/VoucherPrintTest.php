<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Print\PaperSize;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ভাউচারের কাগজ — টাকা হাতবদলের একমাত্র প্রমাণ।
 *
 * ছাপা বসানো হয়েছিল বিক্রয়ের ছয়টা ডকুমেন্টে আর সেখানেই থেমে ছিল, অথচ
 * ভাউচারই সেই কাগজ যেটা সত্যিই হাতে হাতে যায়: গ্রাহক টাকা দিলে তাঁকে
 * রসিদ দিতে হয়। না দিলে পরদিন "আমি দিয়েছি" বনাম "পাইনি", আর দুই পক্ষের
 * কারও হাতে কিছু নেই।
 */
class VoucherPrintTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();
    }

    /**
     * নগদ প্রাপ্তির একটা ভাউচার।
     *
     * পাল্টা খাতটা ৫২০২ (দোকান ভাড়া) — বিক্রয় ৪১০০ নয়, কারণ ওটা গ্রুপ
     * খাত আর গ্রুপে সরাসরি লেনদেন বসে না। এখানে কোন খাত তা গুরুত্বপূর্ণ
     * নয়; কাগজটা বেরোয় কি না সেটাই প্রশ্ন।
     */
    private function receipt(): Voucher
    {
        /*
         * নগদের খাতটা টিলের খাত, ১১০১ নয়।
         *
         * ১১০১ ("হাতে নগদ") একটা গ্রুপ, আর গ্রুপে সরাসরি লেনদেন বসে না।
         * ওটা দিয়ে খসড়া তৈরি হয় ঠিকই, কিন্তু পোস্ট করতে গেলে আটকায় —
         * তাই বাতিলের পরীক্ষাটা প্রথমে এখানেই থেমে গিয়েছিল।
         *
         * `ensurePrimaryTill()` প্রতিটা কোম্পানির প্রধান ড্রয়ার আর তার
         * নিজের postable খাত তৈরি করে। VoucherTest-ও ঠিক এটাই করে, আর
         * ওখানে পোস্ট করা কাজ করে — কাজ করা জিনিসটাই নকল করা হলো।
         */
        $cash = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $other = Account::query()->where('code', '5202')->firstOrFail();

        return app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'নগদ প্রাপ্তি',
        ], [
            ['account_id' => $cash, 'debit' => '1500', 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => '1500'],
        ]);
    }

    // ── কাগজটা বেরোয় ──────────────────────────────────────────────

    public function test_a_voucher_prints(): void
    {
        $this->get(route('accounts.voucher.print', $this->receipt()))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * তিনটা কাগজের মাপেই — ৫৮মিমি, ৮০মিমি, A4।
     *
     * কাউন্টারে ৮০মিমি রোল, হাতের ছোট মেশিনে ৫৮, আর অফিসের ফাইলে A4।
     * একটা মাপ কাজ না করলে ঠিক সেই জায়গাটাতেই কাগজ দেওয়া যেত না।
     */
    public function test_it_prints_on_all_three_paper_sizes(): void
    {
        $voucher = $this->receipt();

        foreach (PaperSize::all() as $paper) {
            $this->get(route('accounts.voucher.print', $voucher).'?paper='.$paper)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
    }

    /**
     * অজানা মাপ চাইলেও কাগজটা বেরোয়।
     *
     * পুরনো বুকমার্ক বা হাতে বদলানো ঠিকানার জন্য একজন ক্যাশিয়ারের রসিদ
     * আটকে যাওয়ার কোনো কারণ নেই — A4-এ পড়ে যায়।
     */
    public function test_an_unknown_paper_size_falls_back_instead_of_failing(): void
    {
        $this->get(route('accounts.voucher.print', $this->receipt()).'?paper=a3')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ── কে ছাপতে পারে ─────────────────────────────────────────────

    /**
     * ভাউচার দেখার অনুমতি নেই যাঁর, তিনি ছাপতেও পারেন না।
     *
     * অনুমতিটা রুটেই বসানো, কেবল পর্দায় নয়: মেনু লুকানো থাকলেও ঠিকানা
     * টাইপ করে PDF নামিয়ে ফেলা যেন না যায়।
     */
    public function test_someone_without_the_permission_cannot_print(): void
    {
        $voucher = $this->receipt();

        $outsider = User::factory()->create();
        $outsider->companies()->attach($this->company, ['is_active' => true]);
        $outsider->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($outsider)
            ->get(route('accounts.voucher.print', $voucher))
            ->assertForbidden();
    }

    public function test_a_guest_gets_the_login_screen_not_a_pdf(): void
    {
        $voucher = $this->receipt();

        auth()->logout();

        $this->get(route('accounts.voucher.print', $voucher))->assertRedirect(route('login'));
    }

    // ── কাগজে যা থাকতেই হবে ──────────────────────────────────────

    /**
     * বাতিল ভাউচারও ছাপা যায় — কিন্তু গায়ে "বাতিল" লেখা থাকে।
     *
     * ছাপতে না দিলে "ওই রসিদটার কী হলো" প্রশ্নের উত্তর দেখানো যেত না।
     * কিন্তু চালু রসিদের মতো দেখতে একটা বাতিল রসিদ নিয়ে কেউ ফেরত দেওয়া
     * টাকা আবার দাবি করতে পারেন, তাই কাগজটাকে নিজেই সেটা বলতে হয়।
     *
     * ── PDF-এর ভেতরে লেখা খোঁজা হয় না ───────────────────────────────
     * mPDF-এর বাইনারিতে বাংলা লেখা সাবসেট করা ফন্টে এনকোড হয়ে বসে, তাই
     * "বাতিল" শব্দটা ওখানে খুঁজে পাওয়া যায় না — পাওয়া না যাওয়াটা কিছুই
     * প্রমাণ করে না। তাই যাচাইটা এক ধাপ আগে: কন্ট্রোলার টেমপ্লেটকে
     * `notice` পাঠায় কি না, আর সেটা বাতিল হলেই কেবল।
     */
    public function test_a_cancelled_voucher_says_so_on_the_paper(): void
    {
        $voucher = $this->receipt();

        app(VoucherService::class)->post($voucher);
        app(VoucherService::class)->cancel($voucher->fresh(), 'ভুল খাতে বসেছিল');

        $seen = [];
        View::composer('print.voucher', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('accounts.voucher.print', $voucher))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(__('accounts::print.cancelled'), $seen['notice'] ?? null,
            'বাতিল ভাউচারের কাগজে "বাতিল" ছাপ বসেনি — চালু রসিদের মতোই দেখাবে।');
    }

    /** চালু ভাউচারে ওই ছাপটা থাকে না। */
    public function test_a_live_voucher_carries_no_cancelled_stamp(): void
    {
        $voucher = $this->receipt();

        $seen = [];
        View::composer('print.voucher', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('accounts.voucher.print', $voucher))->assertOk();

        $this->assertNull($seen['notice'] ?? null,
            'চালু ভাউচারের কাগজে "বাতিল" ছাপ বসেছে।');
    }

    /**
     * জার্নাল ভাউচারও ছাপা যায়।
     *
     * এক টেমপ্লেট পাঁচ ধরনের জন্য, তাই একটায় চললে বাকিগুলোতেও চলার কথা।
     * "চলার কথা" আর "চলে" এক জিনিস নয় — বিশেষ করে জার্নালে, যেখানে কোনো
     * পক্ষ থাকে না আর সইয়ের ঘরও আলাদা।
     */
    public function test_a_journal_voucher_prints_too(): void
    {
        $expense = Account::query()->where('code', '5208')->firstOrFail();
        $cash = Account::query()->where('code', StandardChart::CASH_IN_HAND)->firstOrFail();

        $journal = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'অফিসের বিস্কুট',
        ], [
            ['account_id' => $expense->id, 'debit' => '450', 'credit' => '0',
                'narration' => 'এক কার্টন বিস্কুট'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => '450'],
        ]);

        $this->get(route('accounts.voucher.print', $journal))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
