<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ কাগজে ব্যবহৃত একক, কর আর পরিশোধ-পদ্ধতির টাকার ঘর জমে থাকে — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (মাস্টার ২৪;
 * [[MasterListService::update()]])।
 *
 * ⓘ পাহারা ছিল কেবল মোছায়। ব্যবহৃত এককের গুণক বদলালে পুরনো কাগজের পরিমাণের মানে বদলাত, করের হার বদলালে পুরনো পণ্যের দর, পরিশোধ-
 * পদ্ধতির ধরন বদলালে পুরনো ভাউচারে লেখা কোডের মানে (নগদ হয়ে যেত চেক)।
 */
final class AUsedMasterKeepsItsMoneyFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_used_unit_keeps_its_factor_but_may_be_renamed(): void
    {
        $unit = Unit::query()->whereIn('id', Product::query()->select('unit_id'))->firstOrFail();

        $this->assertRefused(fn () => app(MasterListService::class)->update($unit, ['name_en' => $unit->name_en, 'factor' => bcadd((string) ($unit->factor ?? '1'), '11', 4)]), 'factor');
        $this->assertSame('Renamed Unit', app(MasterListService::class)->update($unit->fresh(), ['name_en' => 'Renamed Unit', 'factor' => (string) $unit->factor])->name_en,
            'নাম বদলানো (আর না-বদলানো গুণক পাঠানো) চলার কথা');
    }

    public function test_a_used_tax_keeps_its_rate(): void
    {
        $tax = Tax::query()->firstOrFail();
        Product::query()->whereKey(Product::query()->value('id'))->update(['tax_id' => $tax->id]);

        $this->assertRefused(fn () => app(MasterListService::class)->update($tax, ['name_en' => $tax->name_en, 'rate' => bcadd((string) $tax->rate, '5', 4)]), 'rate');
        $this->assertSame(0, bccomp((string) $tax->fresh()->rate, (string) $tax->rate, 4));
    }

    public function test_a_payment_method_written_on_a_voucher_keeps_its_kind_and_code(): void
    {
        $method = PaymentMethod::query()->firstOrFail();
        $spare = PaymentMethod::query()->whereKeyNot($method->id)->firstOrFail();
        $other = $method->kind === 'cheque' ? 'cash' : 'cheque';

        // ⓘ একটা খসড়া পরিশোধ-ভাউচার, এই পদ্ধতির কোডে
        app(StandardChart::class)->install();
        $cash = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $expense = (int) Account::query()->postable()->active()->where('type', 'expense')->value('id');
        app(VoucherService::class)->create(['type' => 'payment', 'trx_date' => now()->toDateString(),
            'narration' => 'Paid by the method', 'instrument' => $method->code],
            [['account_id' => $expense, 'debit' => '100', 'credit' => '0'], ['account_id' => $cash, 'debit' => '0', 'credit' => '100']]);
        $this->assertTrue(DB::table('vouchers')->where('instrument', $method->code)->exists(), 'দৃশ্যটাই বানানো যায়নি — কোনো ভাউচার নেই');

        $this->assertRefused(fn () => app(MasterListService::class)->update($method, ['name_en' => $method->name_en, 'kind' => $other]), 'kind');
        $this->assertRefused(fn () => app(MasterListService::class)->update($method->fresh(), ['name_en' => $method->name_en, 'code' => 'NEW-CODE']), 'code');

        // ⓘ কোথাও না লেখা পদ্ধতি — আগের মতোই বদলায়
        if (! DB::table('vouchers')->where('instrument', $spare->code)->exists()) {
            app(MasterListService::class)->update($spare, ['name_en' => $spare->name_en, 'code' => 'SPARE-NEW']);
            $this->assertSame('SPARE-NEW', $spare->fresh()->code, 'অব্যবহৃত পদ্ধতির কোড বদলানোর কথা');
        }
    }

    private function assertRefused(callable $what, string $field): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), 'অন্য ঘরে আটকাল: '.implode(', ', array_keys($e->errors())));

            return;
        }

        $this->fail("⛔ ব্যবহৃত সারির «{$field}» বদলে গেল — পুরনো কাগজের মানে বদলাল");
    }
}
