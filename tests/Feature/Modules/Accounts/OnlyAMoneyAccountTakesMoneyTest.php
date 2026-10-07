<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\MoneyAccountRule;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * টাকা কেবল টাকার খাতে বসে — নিয়মটা হিসাব-মডিউলে, সবার জন্য এক।
 *
 * ── কেন (২৭ সেপ্টেম্বর ২০২৬) ─────────────────────────────────────────
 * নিয়মটা ছিল বিক্রয়ের ভেতরে, তাই সরাসরি ক্রয়ের পরিশোধ ওটা ডাকতে পারত না —
 * সেখানে যাচাই কেবল "খাতটা আছে কি না", ফলে খরচের খাত থেকেও "পরিশোধ" হত।
 * এখন নিয়মটা হিসাব-মডিউলে; এই পরীক্ষা নিয়মটাকেই সরাসরি ধরে, কোনো পর্দা
 * ছাড়া, যাতে কোনো দরজা ডাকতে ভুলে গেলেও নিয়মের নিজের কথা প্রমাণিত থাকে।
 */
final class OnlyAMoneyAccountTakesMoneyTest extends TestCase
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

    public function test_a_leaf_under_each_money_head_is_accepted(): void
    {
        foreach (StandardChart::MONEY_PARENTS as $head) {
            $leaf = $this->leafUnder($head);

            $this->assertSame(
                $leaf->id,
                app(MoneyAccountRule::class)->assert($leaf->id)->id,
                "মাথা {$head}-এর নিচের খাত টাকা নিতে পারল না।",
            );
        }
    }

    public function test_an_expense_account_is_refused_on_the_named_field(): void
    {
        $expense = StandardChart::find(StandardChart::BANK_CHARGES);

        $this->assertRefused($expense->id, 'payments', 'payments');
    }

    public function test_a_money_head_itself_is_refused(): void
    {
        foreach (StandardChart::MONEY_PARENTS as $head) {
            $this->assertRefused(StandardChart::find($head)->id);
        }
    }

    public function test_cheques_in_hand_only_when_asked_for(): void
    {
        $cheques = StandardChart::find(StandardChart::CHEQUES_IN_HAND);

        $this->assertRefused($cheques->id);

        $this->assertSame(
            $cheques->id,
            app(MoneyAccountRule::class)->assert($cheques->id, allowHolding: true)->id,
        );
    }

    public function test_an_account_that_is_not_there_is_refused(): void
    {
        $this->assertRefused((int) Account::query()->max('id') + 1000);
    }

    private function leafUnder(string $headCode): Account
    {
        $head = StandardChart::find($headCode);

        $leaf = Account::query()
            ->where('parent_id', $head->id)
            ->where('is_group', false)
            ->first();

        return $leaf ?? Account::query()->create([
            'code' => $headCode.'-T',
            'name_en' => 'Test '.$headCode,
            'name_bn' => 'Test '.$headCode,
            'type' => $head->type,
            'parent_id' => $head->id,
            'is_group' => false,
            'nature' => Account::defaultNatureFor($head->type),
        ]);
    }

    private function assertRefused(int $accountId, string $field = 'account_id', string $expectedKey = 'account_id'): void
    {
        try {
            app(MoneyAccountRule::class)->assert($accountId, false, $field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($expectedKey, $e->errors(), 'ত্রুটি ভুল ঘরে দেখাল।');

            return;
        }

        $this->fail("খাত #{$accountId}-কে টাকার খাত বলে মেনে নেওয়া হল।");
    }
}
