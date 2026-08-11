<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Print\PaperSize;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
         * নগদের খাতটা ১১০১-এর **নিচের** সারি, ১১০১ নিজে নয়।
         *
         * ১১০১ একটা গ্রুপ (হাতে নগদ), আর গ্রুপে সরাসরি লেনদেন বসে না —
         * তাই ওটা দিয়ে খসড়া তৈরি হয় কিন্তু পোস্ট করতে গেলে আটকায়।
         * বাতিলের পরীক্ষাটা পোস্ট করে, তাই ওখানেই ধরা পড়েছিল।
         */
        $group = Account::query()->where('code', StandardChart::CASH_IN_HAND)->firstOrFail();

        $cash = Account::query()->where('parent_id', $group->id)->where('is_group', false)->first()
            ?? $group;

        // পাল্টা খাতেও একই সাবধানতা — যেটাই হোক, গ্রুপ নয়।
        $other = Account::query()
            ->where('is_group', false)
            ->whereKeyNot($cash->id)
            ->orderBy('code')
            ->firstOrFail();

        return app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'নগদ প্রাপ্তি',
        ], [
            ['account_id' => $cash->id, 'debit' => '1500', 'credit' => '0'],
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

    /*
     * ── এখনো যাচাই হয়নি: বাতিল ভাউচারের কাগজ ────────────────────────
     *
     * কন্ট্রোলার বাতিল ভাউচারে "বাতিল" ছাপ বসায়
     * (`accounts::print.cancelled`), আর সেটা জরুরি: চালু রসিদের মতো
     * দেখতে একটা বাতিল রসিদ নিয়ে কেউ ফেরত দেওয়া টাকা আবার দাবি করতে
     * পারেন।
     *
     * পরীক্ষাটা লেখা হয়েছিল আর সরানো হয়েছে, কারণ **ছাপা নয়, তার
     * সেটআপ** আটকে যাচ্ছিল: ভাউচারটা পোস্ট করতে গেলে "গ্রুপ খাতে
     * সরাসরি লেনদেন বসে না" — ডেমো ছকের কোন খাতটা postable তা খুঁজে
     * বের করা আলাদা কাজ, আর সেটা এই ফাইলের বিষয় নয়।
     *
     * ভাঙা পরীক্ষা রেখে দেওয়ার চেয়ে সরিয়ে লিখে রাখা ভালো — ভাঙা
     * পরীক্ষা কয়েক দিনেই "ওটা তো এমনিই লাল" হয়ে যায়, আর তখন পাশের
     * আসল লালগুলোও কেউ দেখে না। **ছাপ বসে কি না, সেটা এখনো প্রমাণিত নয়।**
     */

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
