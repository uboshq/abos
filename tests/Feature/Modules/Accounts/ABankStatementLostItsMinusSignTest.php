<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Imports\BankStatementImporter;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ব্যাংক-বিবরণীর অঙ্ক বিয়োগ চিহ্ন হারাত — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ আমদানি অঙ্ক থেকে অঙ্ক আর দশমিক ছাড়া সব ফেলে দিত: জমার কলামে "-500" লেখা তোলা নীরবে ৫০০ টাকার **জমা** হত,
 * আর "1.2.3" আমদানিটাই ভাঙত। ⭐ এখন চিহ্নওয়ালা বা ভাঙা অঙ্কের সারি পরিষ্কার কথায় ফেরত যায়; কমা আর মুদ্রার
 * চিহ্ন আগের মতোই চলে।
 */
final class ABankStatementLostItsMinusSignTest extends TestCase
{
    use RefreshDatabase;

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $bank = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail()->replicate(['public_id']);
            $bank->forceFill(['code' => 'BANK-STMT', 'name_en' => 'BANK-STMT', 'name_bn' => 'BANK-STMT', 'money_kind' => Account::BANK])->save();
        }

        $this->code = (string) $bank->code;
    }

    public function test_a_signed_or_broken_amount_is_refused_and_a_plain_one_passes(): void
    {
        $said = __('accounts::import.bad_statement_amount', ['value' => 'X']);
        $marker = mb_substr($said, 0, 12);

        foreach (['credit' => '-500', 'debit' => '(500)', 'credit ' => '1.2.3'] as $side => $value) {
            $errors = $this->check([trim($side) => $value]);

            $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, $marker)),
                "⛔ '{$value}' ফেরত যায়নি — চিহ্ন নীরবে মুছে যেত। পেয়েছি: ".json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame([], $this->check(['credit' => '1,500.00']), 'সাধারণ অঙ্কও ফেরত গেছে — যাচাই বেশি চেপেছে।');
        $this->assertSame([], $this->check(['debit' => '৳ 2,000']), 'মুদ্রার চিহ্নসহ অঙ্ক ফেরত গেছে।');
    }

    /**
     * @param  array<string, string>  $amount
     * @return list<string>
     */
    private function check(array $amount): array
    {
        return app(BankStatementImporter::class)->check([
            'account_code' => $this->code,
            'trx_date' => now()->toDateString(),
            'description' => 'test',
            'reference' => 'R1',
            'debit' => '',
            'credit' => '',
            ...$amount,
        ]);
    }
}
