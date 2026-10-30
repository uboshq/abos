<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ভাউচারের প্রিভিউ শাখাওয়ালা পক্ষকে ফেরায় না — লাইভে ধরা (cb, ২৫ অক্টোবর ২০২৬; 84b7a0800 থেকে)।
 *
 * ⓘ প্রিভিউ পোস্টের একই পাহারা চালায় ([[VoucherService::ledgerPreview()]]), কিন্তু তার খসড়া কাগজে শাখা বসত না। তাই
 * পক্ষের শাখার পাহারা পক্ষের শাখাকে "#0"-এর সাথে মেলাত, আর শাখা-বসানো প্রতিটা গ্রাহক বা সরবরাহকারীর রসিদ-পরিশোধের
 * প্রিভিউ "এই পক্ষ … শাখার — তার টাকা "#0" শাখায় লেখা যায় না" বলত — ওয়েবে আর ফোনে। পোস্ট ঠিক ছিল।
 *
 * দাবি:
 *   ১. নিজের শাখার পক্ষ — প্রিভিউ খাতার সারি দেখায়, কোনো বাধা নয়
 *   ২. পাহারা হারায়নি — লেখক অন্য শাখা দিলে অন্য শাখার পক্ষ এখনো নিজের বার্তায় ফেরে, আর বার্তায় দুই শাখার আসল নাম ("#0" নয়)
 */
final class ThePreviewKnowsThePartysBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $home;

    private Account $bank;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        CompanyContext::set($this->company->id, $this->home->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create(['code' => StandardChart::BANK.'-01', 'name_en' => 'Preview branch bank',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')]);
        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
    }

    /** ১ */
    public function test_a_party_of_this_branch_previews_its_ledger_rows(): void
    {
        $this->customer->forceFill(['branch_id' => $this->home->id])->save();

        $answer = $this->preview();

        $this->assertSame('', (string) ($answer['message'] ?? ''),
            '⛔ নিজের শাখার পক্ষের প্রিভিউ থামল — প্রিভিউর কাগজে পোস্টের শাখা নেই: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($answer['rows'] ?? [], '⛔ নিজের শাখার পক্ষের প্রিভিউতে খাতার সারি নেই: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
    }

    /**
     * ৩ — বিবরণ ছাড়াই সারাংশ (মালিক, ২৬ অক্টোবর ২০২৬: "vauthucer e sumrry ekhono asena keno?" — লাইভে প্রতিটা প্রিভিউ
     * `missing: [narration]` পেয়ে খালি ছিল); বিবরণ অঙ্কের কিছু বদলায় না। ⛔ জমায় বিবরণ আগের মতোই লাগে।
     */
    public function test_the_summary_comes_before_the_narration_is_written(): void
    {
        $this->customer->forceFill(['branch_id' => $this->home->id])->save();

        $answer = $this->preview(['narration' => '']);

        $this->assertNotContains('narration', $answer['missing'] ?? [], '⛔ বিবরণ না লিখলে সারাংশ আসে না — প্রিভিউ বিবরণের অপেক্ষায়');
        $this->assertNotEmpty($answer['rows'] ?? [], '⛔ বিবরণ ছাড়া প্রিভিউতে খাতার সারি নেই: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($answer['summary'] ?? null, '⛔ বিবরণ ছাড়া প্রিভিউতে সারাংশ নেই');

        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT, 'narration' => '', 'trx_date' => now()->toDateString(), 'amount' => '100',
            'instrument' => 'transfer', 'instrument_no' => 'PVB-N', 'party_type' => 'customer', 'party_id' => $this->customer->id,
            'to_account_id' => $this->bank->id,
        ])->assertSessionHasErrors('narration');
    }

    /** ২ */
    public function test_a_party_of_another_branch_is_still_refused_by_name(): void
    {
        $other = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'PVB', 'name_en' => 'Preview Other Branch']);
        $this->customer->forceFill(['branch_id' => $other->id])->save();

        /*
         * ⓘ শাখা না দিলে ভাউচার পক্ষের শাখাতেই বসে ([[VoucherService::branchOfTheVoucher()]]) — তখন ফেরানোর কিছু নেই। পাহারা ধরে যখন
         * লেখক নিজে অন্য শাখা দেন: সেটাই "#0" দেখাত, কারণ প্রিভিউর কাগজে শাখা ছিল না।
         */
        $answer = $this->preview(['branch_id' => $this->home->id]);

        $this->assertSame([], $answer['rows'] ?? null, '⛔ অন্য শাখার পক্ষের প্রিভিউ খাতার সারি দেখাল — শাখার পাহারা হারাল');
        $message = (string) ($answer['message'] ?? '');
        $this->assertStringContainsString('Preview Other Branch', $message, '⛔ পক্ষের শাখার পাহারা থামাল না, বা অন্য কারণে থামল: '.$message);
        $this->assertStringContainsString($this->home->name(), $message, '⛔ বার্তায় যে শাখায় লেখা হচ্ছিল তার নাম নেই: '.$message);
        $this->assertStringNotContainsString('#0', $message, '⛔ বার্তায় "#0" — প্রিভিউর কাগজে এখনো শাখা নেই: '.$message);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function preview(array $extra = []): array
    {
        return $this->postJson(route('accounts.voucher.simulate'), [
            'type' => Voucher::RECEIPT, 'narration' => 'প্রিভিউর শাখা', 'trx_date' => now()->toDateString(), 'amount' => '100',
            'instrument' => 'transfer', 'instrument_no' => 'PVB-1', 'party_type' => 'customer', 'party_id' => $this->customer->id,
            'to_account_id' => $this->bank->id, ...$extra,
        ])->assertOk()->json();
    }
}
