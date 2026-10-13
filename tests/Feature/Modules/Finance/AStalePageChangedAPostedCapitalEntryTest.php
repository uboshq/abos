<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ মূলধনের খসড়া বদলানো/মোছা যাচাই করত হাতের কপিতে — পুরনো পাতা থেকে বদলালে মাঝে রসিদে পাকা হওয়া সারির অঙ্ক বদলে যেত, খাতা
 * আর রেজিস্টার দুই কথা বলত (লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CapitalService::revise()]])।
 */
final class AStalePageChangedAPostedCapitalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_draft_cannot_change_or_remove_a_posted_entry(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $cash = Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();

        $person = Person::query()->create(['code' => 'CAP-STALE', 'name_en' => 'Stale Partner', 'name_bn' => 'Stale Partner', 'is_active' => true]);
        $terms = ['person_id' => $person->id, 'contributor_type' => CapitalEntry::PARTNER, 'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(), 'amount' => '50000'];
        $stale = app(CapitalService::class)->record($terms);

        app(CapitalService::class)->post(CapitalEntry::query()->findOrFail($stale->id), $cash);
        $this->assertSame(CapitalEntry::POSTED, $stale->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — সারি পাকা হয়নি');

        foreach ([fn () => app(CapitalService::class)->revise($stale, ['amount' => '90000'] + $terms),
            fn () => app(CapitalService::class)->discard($stale)] as $step) {
            try {
                $step();
                $this->fail('⛔ পুরনো পাতা থেকে পাকা মূলধনের সারি বদলাল বা মুছল');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, bccomp((string) $stale->fresh()->amount, '50000', 4), '⛔ পাকা সারির অঙ্ক বদলে গেল — খাতায় ৫০,০০০');
    }
}
