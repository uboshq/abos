<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ইঞ্জিন টাকার অঙ্ক কাটত, গোল করত না — আর বৈজ্ঞানিক রূপে ভেঙে পড়ত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, হিসাব ⓘ১৯)।
 *
 * ⓘ [[PostingEngine::normalise()]] `bcadd((string) $amount, '0', 4)` করত: ১০০০.০০০০৫ (float) কেটে ১০০০.০০০০ হত, তাই ক্রেডিট
 * ১০০০.০০০১-এর সাথে দাখিলা "মেলে না"; আর "5.0E-5" এলে bcmath ValueError। এখন চার ঘরে, অর্ধেকে উপরে গোল।
 */
final class TheEngineRoundsAmountsInsteadOfCuttingThemTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fifth_decimal_rounds_up_and_a_scientific_figure_is_read(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $debit = StandardChart::find(StandardChart::SALARY_EXPENSE)->id;
        $credit = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;

        // ⓘ float-এর পঞ্চম ঘর — কেটে নয়, গোল করে ১০০০.০০০১; তাই ক্রেডিটের সাথে মেলে
        app(PostingEngine::class)->post('test:round', 1, now()->toDateString(), [
            ['account_id' => $debit, 'debit' => 1000.00005],
            ['account_id' => $credit, 'credit' => '1000.0001'],
        ]);
        $this->assertSame('1000.0001', bcadd((string) LedgerEntry::query()->where('source_type', 'test:round')->where('account_id', $debit)->value('debit'), '0', 4),
            '⛔ পঞ্চম ঘর কাটা হল, গোল নয়');

        // ⓘ বৈজ্ঞানিক রূপ — float আর লেখা দুইটাই
        app(PostingEngine::class)->post('test:sci', 2, now()->toDateString(), [
            ['account_id' => $debit, 'debit' => 5.0E-5],
            ['account_id' => $credit, 'credit' => '5.0E-5'],
        ]);
        $this->assertSame('0.0001', bcadd((string) LedgerEntry::query()->where('source_type', 'test:sci')->where('account_id', $credit)->value('credit'), '0', 4),
            '⛔ বৈজ্ঞানিক রূপের অঙ্ক ঠিক পড়া হয়নি');

        // ⓘ ঋণাত্মক দিকেও অর্ধেকে দূরে — সাধারণ অঙ্ক আগের মতোই
        app(PostingEngine::class)->post('test:plain', 3, now()->toDateString(), [
            ['account_id' => $debit, 'debit' => '250.5'],
            ['account_id' => $credit, 'credit' => 250.5],
        ]);
        $this->assertSame('250.5000', bcadd((string) LedgerEntry::query()->where('source_type', 'test:plain')->where('account_id', $credit)->value('credit'), '0', 4));

        // ⓘ বড় float — দশ ঘরে লিখলে বাইনারির ১২৩৪৫৬৭৮৯.১২৩৪৪৯৯৯… বেরোয়, নিচে গোল হত; সবচেয়ে ছোট রূপ "…12345" থেকে উপরে
        app(PostingEngine::class)->post('test:big', 4, now()->toDateString(), [
            ['account_id' => $debit, 'debit' => 123456789.12345],
            ['account_id' => $credit, 'credit' => '123456789.1235'],
        ]);
        $this->assertSame('123456789.1235', bcadd((string) LedgerEntry::query()->where('source_type', 'test:big')->where('account_id', $debit)->value('debit'), '0', 4),
            '⛔ বড় float বাইনারির ভুলে নিচে গোল হল');
    }
}
