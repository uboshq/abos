<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অংশ % নিজেই হিসাব হয় — কেউ হাতে না লিখলেও।
 *
 * ── ⛔ কী ঘটছিল, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * মালিক "মূলধন ও বিনিয়োগ" পর্দায় দেখলেন তিনিই একমাত্র মালিক, দিয়েছেন
 * ২৫,০০,০০০ — অথচ "অংশ %" আর "চলতি লাভের অংশ" দুটোই ড্যাশ। বললেন:
 * *"eta to auto hisab kore bosar kotha"*। ⓘ অংশ আসত কেবল জমার সময় হাতে
 * লেখা শতাংশ থেকে, আর না লিখলে লাভের অংশও হারাত।
 *
 * ⭐ নিয়ম: চুক্তির শতাংশ লেখা থাকলে সেটাই; বাকিরা বাকিটা ভাগ করেন বাকি
 * মূলধনের অনুপাতে। তিনটা অবস্থা মাপা হয় — একা মালিক, দুই জন কেউ না
 * লিখে, আর একজন চুক্তিতে বাকি দুইজন মূলধনে।
 */
final class TheShareWasBlankBecauseNobodyTypedItTest extends TestCase
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

    public function test_a_sole_owner_holds_the_whole_share_and_the_whole_profit(): void
    {
        $this->contribute('Md. Alamin', '2500000');

        $me = $this->positions('90000')['Md. Alamin'];

        $this->assertSame(0, bccomp($me['share'], '100', 4), "অংশ {$me['share']}, ১০০ হওয়ার কথা।");
        $this->assertSame('capital', $me['share_source']);
        $this->assertSame(0, bccomp($me['profit_share'], '90000', 4), 'একা মালিক পুরো লাভের অংশ পাননি।');
    }

    public function test_two_partners_share_by_what_they_put_in(): void
    {
        $this->contribute('Rahim', '600000');
        $this->contribute('Karim', '400000');

        $p = $this->positions('10000');

        $this->assertSame(0, bccomp($p['Rahim']['share'], '60', 4));
        $this->assertSame(0, bccomp($p['Karim']['share'], '40', 4));
        $this->assertSame(0, bccomp($p['Karim']['profit_share'], '4000', 4));
    }

    public function test_an_agreed_share_stands_and_the_rest_split_the_remainder(): void
    {
        $this->contribute('Rahim', '100000', share: '50');
        $this->contribute('Karim', '300000');
        $this->contribute('Salam', '100000');

        $p = $this->positions(null);

        $this->assertSame(0, bccomp($p['Rahim']['share'], '50', 4), 'চুক্তির ৫০% মূলধনের হিসাবে বদলে গেছে।');
        $this->assertSame('agreed', $p['Rahim']['share_source']);
        $this->assertSame(0, bccomp($p['Karim']['share'], '37.5', 4));
        $this->assertSame(0, bccomp($p['Salam']['share'], '12.5', 4));
        $this->assertNull($p['Karim']['profit_share'], 'লাভ না দিলে লাভের অংশ ড্যাশ থাকার কথা।');
    }

    private function contribute(string $who, string $amount, ?string $share = null): void
    {
        $person = Person::query()->firstOrCreate(
            ['company_id' => CompanyContext::id(), 'name_en' => $who],
            ['code' => 'P-'.mb_strtoupper(mb_substr($who, 0, 5))],
        );

        $entry = app(CapitalService::class)->record([
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'share_percent' => $share ?? '',
        ]);

        $cash = Account::query()->postable()->where('name_en', 'like', '%Cash%')->orderBy('code')->firstOrFail();

        app(CapitalService::class)->post($entry, $cash);
    }

    /** @return array<string, array<string, mixed>> */
    private function positions(?string $profit): array
    {
        return collect(app(CapitalService::class)->positions($profit))->keyBy('name')->all();
    }
}
