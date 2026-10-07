<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * খাতার নিয়ন্ত্রণ — যে কাজ চোখের আড়ালে হয়, সেটা একটা পর্দায়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: *"Finance map 100% complate korba"*। ফিন্যান্স মানচিত্রের পাঁচটা
 * লাইন ("পোস্টিং মনিটর", "ব্যর্থ পোস্টিংয়ের সারি", "মাস-শেষের চেকলিস্ট",
 * "পটভূমির কাজ ও সতর্কতা", "নম্বর সিরিজ মেলানো") বাকি ছিল। ⓘ মাপ রেন্ডার
 * হওয়া পাতায়: পুরনো খসড়া "আটকে আছে"-তে আসে, চেকলিস্ট সেটাকে বাকি গোনে,
 * আর পেছনে পড়া সিরিজ বোতাম চাপলে সামনে আসে।
 */
final class TheBooksWorkedOutOfSightTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_every_control_screen_opens(): void
    {
        foreach (['posting', 'failed', 'month_end', 'jobs', 'numbers', 'duplicates'] as $name) {
            $this->get(route('accounts.control.'.$name))->assertOk();
        }

        $this->get(route('accounts.control.posting', ['tab' => 'stuck']))->assertOk();
        $this->get(route('accounts.control.jobs'))->assertSee('abos:books-check');
    }

    public function test_an_old_draft_is_stuck_and_keeps_the_month_open(): void
    {
        $date = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date->toDateString(), 'narration' => 'FORGOTTEN-DRAFT'],
            [
                ['account_id' => Account::query()->where('code', StandardChart::RECEIVABLE)->value('id'), 'debit' => '500'],
                ['account_id' => Account::query()->where('code', StandardChart::PAYABLE)->value('id'), 'credit' => '500'],
            ],
        );

        DB::table('vouchers')->where('id', $voucher->id)->update(['created_at' => now()->subDays(5)]);

        $this->get(route('accounts.control.posting', ['tab' => 'stuck']))
            ->assertOk()
            ->assertSee('FORGOTTEN-DRAFT');

        $html = $this->get(route('accounts.control.month_end', ['month' => $date->format('Y-m')]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-check="drafts" data-state="pending"/', $html, 'পুরনো খসড়া থাকতেও চেকলিস্ট সবুজ।');
        $this->assertMatchesRegularExpression('/data-check="locked" data-state="pending"/', $html, 'মাস খোলা, অথচ "বন্ধ" বলছে।');
        $this->assertSame(7, preg_match_all('/data-check="/', $html), 'চেকলিস্টে সাতটা সারি নেই।');
    }

    public function test_two_customers_on_one_mobile_show_as_one_group(): void
    {
        $two = DB::table('customers')->where('company_id', $this->company->id)->whereNull('deleted_at')
            ->orderBy('id')->limit(2)->get(['id', 'code']);

        $this->assertCount(2, $two, 'ডেমোতে দুইটা গ্রাহক নেই।');

        DB::table('customers')->where('id', $two[0]->id)->update(['phone' => '01711-000999']);
        DB::table('customers')->where('id', $two[1]->id)->update(['phone' => '+8801711000999']);

        $html = $this->get(route('accounts.control.duplicates'))->assertOk()->getContent();

        preg_match_all('/<section[^>]*data-duplicate-group="phone"[^>]*>(.*?)<\/section>/s', $html, $groups);
        $group = collect($groups[1])->first(fn (string $g) => str_contains($g, '01711000999'));

        $this->assertNotNull($group, 'একই মোবাইলের দুই গ্রাহক এক দলে আসেনি।');
        $this->assertStringContainsString($two[0]->code, $group);
        $this->assertStringContainsString($two[1]->code, $group);
    }

    public function test_a_series_left_behind_is_brought_forward(): void
    {
        $series = NumberSeries::query()->where('company_id', $this->company->id)->where('doc_type', 'CUS')->first()
            ?? NumberSeries::query()->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $taken = $series->prefix.'-'.str_pad((string) ($series->next_number + 40), (int) $series->padding, '0', STR_PAD_LEFT);

        DB::table('customers')->where('company_id', $this->company->id)->orderBy('id')->limit(1)->update(['code' => $taken]);

        if ($series->doc_type !== 'CUS') {
            $this->markTestSkipped('ডেমোতে গ্রাহকের সিরিজ নেই।');
        }

        $this->get(route('accounts.control.numbers'))->assertOk()->assertSee($series->prefix);

        $this->post(route('accounts.control.catch_up'))->assertRedirect(route('accounts.control.numbers'));

        $this->assertSame($series->next_number + 41, (int) $series->fresh()->next_number, 'সিরিজ সামনে আসেনি।');
    }
}
