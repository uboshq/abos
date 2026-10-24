<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ খরচের ভাউচারে কেবল-সফটওয়্যারের খাত বাছা যায় না — বিক্রিত মালের খরচ, কেনা দামের পার্থক্য, মজুদের ঘাটতি, অবচয়, দেওয়া ছাড়,
 * সম্পদ বিক্রির ক্ষতি, আগে ভাঙার জরিমানা ([[StandardChart::ENGINE_ONLY_EXPENSES]]; মালিক, ২৪ অক্টোবর ২০২৬, fe: "খরচের তালিকা
 * থেকে COGS আর সফটওয়্যারের নিজের খাত সরানো, সার্ভারের পাহারাসহ — এখন লাইভে ভুল খাতে খরচ বসতে পারে")।
 *
 * ⛔ আগে খরচের খাতের তালিকায় "5100 — Cost of Goods Sold" ছিল (মালিকের ছবিতে আগে থেকেই বসানো)। হাতে বসালে মজুদ আর লাভ দুটোর
 * হিসাব ভাঙে (IAS 2); "দেওয়া ছাড়" হাতে লিখলে ছাড়ের মালিকের সই এড়ানো যেত।
 *
 * দাবি:
 *   ১. ওয়েবের খরচের ফর্মের খরচের খাতে এই সাতটার একটাও নেই, অথচ সাধারণ খরচ (জ্বালানি) আছে
 *   ২. ফোনের খরচের তালিকাতেও একই
 *   ৩. খরচের ভাউচারে বিক্রিত মালের খরচ পাঠালে পোস্ট ফেরে, নিজের বার্তায়, খাতায় কিছু বসে না
 *   ৪. জাবেদায় হিসাবরক্ষকের সংশোধন খোলা — একই খাতে জাবেদা পোস্ট হয়
 */
final class AnExpenseNeverLandsOnTheSoftwaresOwnHeadsTest extends TestCase
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

    public function test_the_ground_is_there(): void
    {
        $this->assertCount(count(StandardChart::ENGINE_ONLY_EXPENSES), $this->engineIds(), 'দৃশ্যটাই ঠিক নয় — মান ছকে সাতটা খাতের সবগুলো নেই');
        $this->assertNotNull(StandardChart::find(StandardChart::FUEL), 'দৃশ্যটাই ঠিক নয় — জ্বালানির খাত নেই');
    }

    /** ১ — ওয়েবের খরচের তালিকা */
    public function test_the_expense_form_offers_none_of_the_softwares_heads(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::EXPENSE]))->assertOk()->getContent();
        preg_match_all('/<option value="(\d+)"/', $html, $m);
        $offered = array_map('intval', $m[1]);

        $this->assertContains((int) StandardChart::find(StandardChart::FUEL)->id, $offered, 'দৃশ্যটাই ঠিক নয় — সাধারণ খরচের খাতই পাতায় নেই');
        foreach ($this->engineIds() as $code => $id) {
            $this->assertNotContains($id, $offered, "⛔ খরচের ফর্মে {$code} বাছা যায় — সফটওয়্যারের নিজের খাতে হাতে খরচ বসত");
        }
    }

    /** ২ — ফোনের খরচের তালিকা */
    public function test_the_phone_offers_none_of_them_either(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $setup = $this->getJson('/api/v1/accounts/vouchers/setup?type=expense')->assertOk()->json();

        $ids = collect([...($setup['to']['accounts'] ?? []), ...($setup['from']['accounts'] ?? [])])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) StandardChart::find(StandardChart::FUEL)->id, $ids, 'দৃশ্যটাই ঠিক নয় — ফোনের তালিকায় সাধারণ খরচই নেই');
        foreach ($this->engineIds() as $code => $id) {
            $this->assertNotContains($id, $ids, "⛔ ফোনের খরচের তালিকায় {$code} — ওয়েবে সরানো, ফোনে খোলা");
        }
    }

    /** ৩ — সার্ভার ফেরায় */
    public function test_an_expense_voucher_on_cost_of_goods_sold_is_refused(): void
    {
        $cogs = StandardChart::find(StandardChart::COST_OF_GOODS_SOLD);
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'narration' => 'হাতে বিক্রিত মালের খরচ'],
            [['account_id' => $cogs->id, 'debit' => '700'], ['account_id' => $cash->id, 'credit' => '700']],
        );

        try {
            app(VoucherService::class)->post($voucher);
            $this->fail('⛔ খরচের ভাউচারে বিক্রিত মালের খরচ পোস্ট হয়ে গেল');
        } catch (ValidationException $e) {
            $said = $e->errors()['lines'][0] ?? json_encode($e->errors());
        }

        $expected = array_map(fn (string $l) => trans('accounts::validation.engine_only_expense', ['name' => $cogs->label($l)], $l), ['bn', 'en']);
        $this->assertContains($said, $expected, '⛔ পোস্ট ফিরল, কিন্তু কেবল-সফটওয়্যারের খাতের কারণে নয়: '.$said);
        $this->assertSame(0, \App\Models\LedgerEntry::query()->where('source_id', $voucher->id)->where('account_id', $cogs->id)->count(),
            '⛔ ফেরানো খরচ খাতায় বসে গেল');
    }

    /** ৪ — জাবেদা খোলা */
    public function test_a_journal_may_still_correct_those_heads(): void
    {
        $cogs = StandardChart::find(StandardChart::COST_OF_GOODS_SOLD);
        $inventory = StandardChart::find(StandardChart::INVENTORY);

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মজুদ গণনার সংশোধন'],
            [['account_id' => $cogs->id, 'debit' => '300'], ['account_id' => $inventory->id, 'credit' => '300']],
        );
        app(VoucherService::class)->post($voucher);

        $this->assertTrue($voucher->fresh()->isPosted(), '⛔ হিসাবরক্ষকের জাবেদাও আটকে গেল — নিষেধ কেবল খরচের ভাউচারে হওয়ার কথা');
    }

    /** @return array<string, int>  কোড => id */
    private function engineIds(): array
    {
        return collect(StandardChart::ENGINE_ONLY_EXPENSES)
            ->mapWithKeys(fn (string $code) => [$code => StandardChart::find($code)?->id])
            ->filter()->map(fn ($id) => (int) $id)->all();
    }
}
