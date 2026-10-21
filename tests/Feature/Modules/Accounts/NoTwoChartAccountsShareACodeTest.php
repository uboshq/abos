<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ ছকের দুইটা খাত একই কোডে নয় — চার শাখা আলাদা আলাদা খাত যোগ করেছিল (PF, উৎসে কর, উৎসব ভাতা, গ্র্যাচুইটি এক শাখায়; WPPF আরেকটায়),
 * আর মেশানোর পরেই প্রথমবার সব একসাথে (a6, ২১ অক্টোবর ২০২৬; [[StandardChart]], [[HrLawAccounts]])।
 *
 * ⓘ কেন: গ্র্যাচুইটি প্রথমে ৫২২৩/২১৩৩ চেয়েছিল — WPPF-এর কোড। একই কোডে দুই ধ্রুবক হলে `install()` প্রথমটা বসিয়ে দ্বিতীয়টা "আছে"
 * ভেবে ছেড়ে দিত, আর গ্র্যাচুইটির খরচ নীরবে WPPF-এর খাতে (বা উল্টো) বসত — মুনাফার ৫%-এর ভিতও বদলাত।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · ছকের বসানোর সারিগুলোয় প্রতিটা কোড একবার।
 *  · ক্লাসের প্রতিটা চার-অঙ্কের কোড-ধ্রুবক (PF_EMPLOYER_EXPENSE, TDS_PAYABLE, FESTIVAL_BONUS_EXPENSE, GRATUITY_EXPENSE, GRATUITY_LIABILITY,
 *    WPPF_EXPENSE, WPPF_PAYABLE আর বাকি সব) আলাদা কোডে, আর প্রতিটা বসানোর সারিতে আছে।
 *  · HR_LAW_CODES-এ WPPF-এর দুই খাতও; পুরনো কোম্পানিতে `abos:hr-law-accounts` সেই দুটোও ঠিক দলে বসায় (খরচ ৫২০০, দায় ২১০০),
 *    দ্বিতীয়বার চালালে কিছুই নয়।
 */
final class NoTwoChartAccountsShareACodeTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ আইনি বেতনের এই সাতটা নাম ধরে — প্রতিফলনের তালিকা থেকে কোনোটা হারালে দাবিটাই থামে */
    private const NAMED = [
        'PF_EMPLOYER_EXPENSE', 'TDS_PAYABLE', 'FESTIVAL_BONUS_EXPENSE', 'GRATUITY_EXPENSE', 'GRATUITY_LIABILITY', 'WPPF_EXPENSE', 'WPPF_PAYABLE',
    ];

    public function test_every_install_row_has_its_own_code(): void
    {
        $codes = array_map(fn (array $row): string => (string) $row[0], $this->chartRows());
        $twice = array_keys(array_filter(array_count_values($codes), fn (int $n): bool => $n > 1));

        $this->assertSame([], $twice, '⛔ ছকে একই কোডে একাধিক সারি: '.implode(', ', $twice));
    }

    public function test_every_code_constant_is_distinct_and_installed(): void
    {
        $constants = $this->codeConstants();
        $installed = array_map(fn (array $row): string => (string) $row[0], $this->chartRows());

        foreach (self::NAMED as $name) {
            $this->assertArrayHasKey($name, $constants, "⛔ {$name} আর ছকের কোড-ধ্রুবক নয়।");
        }

        $byCode = [];
        foreach ($constants as $name => $code) {
            $byCode[$code][] = $name;
        }

        $shared = array_filter($byCode, fn (array $names): bool => count($names) > 1);
        $this->assertSame([], $shared, '⛔ দুই ধ্রুবক একই কোডে: '.json_encode($shared));

        $missing = array_keys(array_filter($constants, fn (string $code): bool => ! in_array($code, $installed, true)));
        $this->assertSame([], $missing, '⛔ ধ্রুবকের কোড ছকে বসে না: '.implode(', ', $missing));

        foreach (StandardChart::HR_LAW_CODES as $code) {
            $this->assertContains($code, $installed, "⛔ আইনি বেতনের খাত {$code} ছকে নেই।");
        }

        $this->assertContains(StandardChart::WPPF_EXPENSE, StandardChart::HR_LAW_CODES, '⛔ WPPF-এর খরচ বসানোর কমান্ডের তালিকায় নেই।');
        $this->assertContains(StandardChart::WPPF_PAYABLE, StandardChart::HR_LAW_CODES, '⛔ WPPF-এর দায় বসানোর কমান্ডের তালিকায় নেই।');
        $this->assertSame(count(StandardChart::HR_LAW_CODES), count(array_unique(StandardChart::HR_LAW_CODES)), '⛔ HR_LAW_CODES-এ একই কোড দুইবার।');
    }

    public function test_the_installer_brings_the_wppf_pair_into_an_existing_company_once(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ WPPF আসার আগের কোম্পানি — দুই খাত নেই, বাকি সব আছে
        DB::table('accounts')->where('company_id', $company->id)
            ->whereIn('code', [StandardChart::WPPF_EXPENSE, StandardChart::WPPF_PAYABLE])->delete();
        $this->assertSame([StandardChart::WPPF_EXPENSE, StandardChart::WPPF_PAYABLE],
            app(StandardChart::class)->missing([StandardChart::WPPF_EXPENSE, StandardChart::WPPF_PAYABLE]), 'প্রস্তুতি — খাত মোছা হলো না।');

        $this->artisan('abos:hr-law-accounts')->assertSuccessful();

        $this->assertSame([], app(StandardChart::class)->missing(StandardChart::HR_LAW_CODES), '⛔ কমান্ডের পরেও আইনি খাত বাকি।');
        $this->assertSame('5200', Account::query()->whereKey(StandardChart::find(StandardChart::WPPF_EXPENSE)->parent_id)->value('code'),
            '⛔ WPPF-এর খরচ পরিচালন ব্যয়ের (৫২০০) নিচে বসল না।');
        $this->assertSame('2100', Account::query()->whereKey(StandardChart::find(StandardChart::WPPF_PAYABLE)->parent_id)->value('code'),
            '⛔ WPPF-এর দায় চলতি দায়ের (২১০০) নিচে বসল না।');

        $before = Account::query()->count();
        $this->artisan('abos:hr-law-accounts')->assertSuccessful();
        $this->assertSame($before, Account::query()->count(), '⛔ দ্বিতীয়বার চালাতে আবার খাত বসল।');
        foreach (StandardChart::HR_LAW_CODES as $code) {
            $this->assertSame(1, Account::query()->where('code', $code)->count(), "⛔ {$code} দুইবার বসল।");
        }
    }

    /** @return array<string, string> ধ্রুবকের নাম => চার-অঙ্কের কোড */
    private function codeConstants(): array
    {
        $out = [];

        foreach ((new \ReflectionClass(StandardChart::class))->getConstants() as $name => $value) {
            if (is_string($value) && preg_match('/^\d{4}$/', $value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @return list<array<int, mixed>> */
    private function chartRows(): array
    {
        $method = new \ReflectionMethod(StandardChart::class, 'definition');
        $method->setAccessible(true);

        return $method->invoke(app(StandardChart::class));
    }
}
