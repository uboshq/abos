<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Imports\OpeningBalanceImporter;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পক্ষ-রাখা খাতে (পাওনা, দেনা, হাতধার) খাতের নিজের খোলা জের নয় — কেবল পক্ষের নামে (cb-র নিরীক্ষা, fe, ১৪ অক্টোবর ২০২৬)।
 *
 * ⓘ নতুন খাতের খোলা জের আর খোলা জেরের আমদানি পাওনা/দেনার খাতে নাম ছাড়া সারি বসাত — খাতের জের আর পক্ষদের যোগ চিরকাল আলাদা,
 * রাতের books-check রোজ "ভাঙা", আর কার পাওনা কেউ জানত না। মালিকের মূলধনে (৩১০০) নাম বাধ্য নয়, তাই সেখানে আগের মতোই।
 */
final class AControlAccountOpensOnlyThroughItsPartiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receivable_refuses_an_unnamed_opening_on_the_form_and_in_the_import(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $this->assertTrue($receivable->holdsParty(), 'দৃশ্যটাই বানানো যায়নি — পাওনার খাত পক্ষ রাখে না');
        $rows = LedgerEntry::query()->count();

        // ⓘ খাতের নিজের খোলা জেরের পথ — ফর্ম, আমদানি আর সেবা সবাই এখানে আসে
        try {
            app(OpeningBalanceService::class)->loadFor($receivable, '5000', now()->toDateString());
            $this->fail('⛔ পাওনার খাতে নাম ছাড়া খোলা জের বসল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.opening_needs_party', ['name' => $receivable->name()]), $e->errors()['opening_balance'][0] ?? null);
        }

        $this->assertSame($rows, LedgerEntry::query()->count(), '⛔ নাম ছাড়া সারি খাতায় বসল');

        // ⓘ আমদানির যাচাইও আগেভাগে বলে
        $this->assertNotSame([], app(OpeningBalanceImporter::class)->check(['account_code' => $receivable->code, 'opening_balance' => '5000']),
            '⛔ আমদানি পাওনার খাতে নাম ছাড়া খোলা জের নিল');

        // ⓘ মালিকের মূলধনে নাম বাধ্য নয় — আগের মতোই
        $capital = StandardChart::find(StandardChart::OWNER_CAPITAL);
        $this->assertSame([], app(OpeningBalanceImporter::class)->check(['account_code' => $capital->code, 'opening_balance' => '5000']));
    }
}
