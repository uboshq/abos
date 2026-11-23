<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\AreaBinding;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Reports\MoneyInReports;
use App\Modules\Sales\Reports\ResponsibleSql;
use App\Modules\Sales\Reports\SalesPlace;
use App\Modules\Sales\Services\SalesResponsibility;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ আসা টাকার কৃতিত্ব দায়িত্বপ্রাপ্ত জনের — রসিদ যিনি লিখেছেন তাঁর নয় (মালিক, ২৩ নভেম্বর ২০২৬)।
 *
 * রসিদে দায়িত্বপ্রাপ্ত জনের ঘর নেই, তাই রিপোর্ট নিয়মটা SQL-এ বসায় ([[ResponsibleSql]])। এই দাবি মেলায় সেই SQL আর মূল নিয়ম
 * ([[SalesResponsibility]]) প্রতিটা ধাপে একই উত্তর দেয়: ডিলারের বাঁধন → জায়গার সিঁড়ি → কোম্পানির ডিফল্ট জন → কেউ না; হাতবদলে দিন ধরে;
 * একই জায়গায় উপরওয়ালা আর নিচের জন থাকলে নিচের জন। শেষে — রিপোর্টের সারিতে টাকাটা সত্যিই সেই জনের নামে ওঠে।
 */
final class TheMoneyInCreditFollowsTheResponsiblePersonTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private SalesResponsibility $who;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ পরিষ্কার মাঠ — ডেমোর কোনো বাঁধন উত্তর ঘোলা না করে
        DB::table('dealer_bindings')->where('company_id', $this->company->id)->delete();
        DB::table('area_bindings')->where('company_id', $this->company->id)->delete();
        DB::table('staff_supervisors')->where('company_id', $this->company->id)->delete();

        $this->who = app(SalesResponsibility::class);
        $this->who->forget();
    }

    public function test_the_sql_gives_the_same_person_as_the_rule_at_every_step(): void
    {
        $area = $this->place('MI-A', Location::AREA);
        $point = $this->place('MI-P', Location::POINT, $area);
        $dealer = $this->dealer('MI-D1', $point);
        $day = now()->toDateString();

        $this->same($dealer, $day, null, 'কেউ দায়িত্বে নেই, সেটিংও নেই');

        $fallback = $this->person();
        app(SettingsService::class)->set(SalesResponsibility::SETTING, $fallback->id);
        $this->same($dealer, $day, $fallback->id, 'কেবল কোম্পানির ডিফল্ট জন');

        $areaMan = $this->person();
        $this->bindArea($areaMan, $area, now()->subYear());
        $this->same($dealer, $day, $areaMan->id, 'পয়েন্টের উপরের এরিয়ায় বাঁধা জন');

        $pointMan = $this->person();
        $this->bindArea($pointMan, $point, now()->subYear());
        $this->same($dealer, $day, $pointMan->id, 'নিজের পয়েন্টে বাঁধা জন — উপরের এরিয়ার জন নন');

        // একই পয়েন্টে উপরওয়ালাও বাঁধা, আগে থেকে — তবু নিচের জন
        $boss = $this->person();
        DB::table('staff_supervisors')->insert(['company_id' => $this->company->id, 'user_id' => $pointMan->id, 'supervisor_id' => $boss->id]);
        $this->bindArea($boss, $point, now()->subYears(2));
        $this->same($dealer, $day, $pointMan->id, 'একই জায়গায় উপরওয়ালা আর নিচের জন — নিচের জন');

        $sr = $this->person();
        $this->bindDealer($sr, $dealer, now()->subMonth());
        $this->same($dealer, $day, $sr->id, 'ডিলারে সরাসরি বাঁধা জন');
        $this->same($dealer, now()->subMonths(2)->toDateString(), $pointMan->id, 'ডিলারের বাঁধনের আগের দিন — তখনকার জায়গার জন');

        // জায়গা ছাড়া ডিলার — ডিফল্ট জন
        $this->same($this->dealer('MI-D2', null), $day, $fallback->id, 'জায়গা বসানো নেই এমন ডিলার');
    }

    public function test_the_receipt_is_counted_under_the_responsible_person_not_under_who_wrote_it(): void
    {
        app(StandardChart::class)->install();
        $bank = Account::query()->create(['company_id' => $this->company->id, 'code' => '1102-MIC', 'name_en' => 'Credit Bank', 'name_bn' => 'কৃতিত্বের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK]);
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $receivable = $receivable->is_group ? Account::query()->postable()->whereKey($receivable->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail() : $receivable;

        $area = $this->place('MI-RA', Location::AREA);
        $point = $this->place('MI-RP', Location::POINT, $area);
        $held = $this->dealer('MI-R1', $point);
        $loose = $this->dealer('MI-R2', null);
        $areaMan = $this->person();
        $fallback = $this->person();
        $this->bindArea($areaMan, $area, now()->subYear());
        app(SettingsService::class)->set(SalesResponsibility::SETTING, $fallback->id);
        $this->who->forget();

        $today = now()->toDateString();
        $id = random_int(1, 4_000_000);
        // ⓘ দুইটা রসিদই মালিক লিখেছেন (লগইন করা জন) — কৃতিত্ব তবু তাঁর নয়
        foreach ([[$held, '900'], [$loose, '350']] as $n => [$dealer, $amount]) {
            app(PostingEngine::class)->post(sourceType: 'receipt_voucher', sourceId: $id + $n, trxDate: $today, lines: [
                ['account_id' => $bank->id, 'debit' => $amount],
                ['account_id' => $receivable->id, 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $dealer],
            ]);
        }

        $rows = collect(app(ReportEngine::class)->run(MoneyInReports::PERSON, ['from' => $today, 'to' => $today, 'account_id' => $bank->id], 1, 500)->rows)
            ->mapWithKeys(fn (array $r) => [(int) $r['person_id'] => bcadd((string) $r['total'], '0', 2)]);

        $this->assertSame('900.00', $rows[$areaMan->id] ?? null, '⛔ এরিয়ায় বাঁধা জনের ডিলারের রসিদ তাঁর নামে ওঠেনি।');
        $this->assertSame('350.00', $rows[$fallback->id] ?? null, '⛔ কারো দায়িত্বে নেই এমন ডিলারের রসিদ কোম্পানির ডিফল্ট জনের নামে ওঠেনি।');
        $this->assertArrayNotHasKey((int) auth()->id(), $rows->all(), '⛔ রসিদ যিনি লিখেছেন তিনিই কৃতিত্ব পেলেন।');

        $byArea = collect(app(ReportEngine::class)->run(MoneyInReports::levelKey(Location::AREA), ['from' => $today, 'to' => $today, 'account_id' => $bank->id], 1, 500)->rows)
            ->mapWithKeys(fn (array $r) => [(string) ($r['place_id'] ?? 'none') => bcadd((string) $r['total'], '0', 2)]);
        $this->assertSame('900.00', $byArea[(string) $area->id] ?? null, '⛔ পয়েন্টের ডিলারের টাকা তার এরিয়ার সারিতে ওঠেনি।');
        $this->assertSame('350.00', $byArea['none'] ?? $byArea[''] ?? null, '⛔ জায়গা বসানো নেই এমন ডিলারের টাকা "জায়গা বসানো নেই" সারিতে নেই।');
    }

    /** মূল নিয়ম আর SQL — দুটোই `$expected` বলে */
    private function same(int $dealer, string $day, ?int $expected, string $case): void
    {
        $this->who->forget();
        $this->assertSame($expected, $this->who->on($dealer, $day), "প্রস্তুতি: {$case} — মূল নিয়মই অন্য উত্তর দিল।");

        $sql = DB::table('customers as cu')
            ->tap(fn ($q) => SalesPlace::join($q))
            ->where('cu.company_id', $this->company->id)
            ->where('cu.id', $dealer)
            ->selectRaw(ResponsibleSql::person($this->company->id, 'cu.id', DB::getPdo()->quote($day)).' as person')
            ->value('person');

        $this->assertSame($expected, $sql === null ? null : (int) $sql, "⛔ {$case}: রিপোর্টের SQL মূল নিয়মের উত্তর দিল না।");
    }

    private function place(string $code, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'parent_id' => $parent?->id,
            'code' => $code, 'name_en' => $code, 'level' => $level, 'is_active' => true,
        ]);
    }

    private function dealer(string $code, ?Location $at): int
    {
        return (int) Customer::query()->create(['code' => $code, 'name_en' => $code, 'is_active' => true, 'location_id' => $at?->id])->id;
    }

    private function person(): User
    {
        $u = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $u->companies()->attach($this->company->id, ['is_active' => true]);

        return $u->fresh();
    }

    private function bindDealer(User $u, int $dealer, \DateTimeInterface $from): void
    {
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $u->id, 'customer_id' => $dealer, 'starts_on' => $from->format('Y-m-d')]);
    }

    private function bindArea(User $u, Location $at, \DateTimeInterface $from): void
    {
        AreaBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $u->id, 'location_id' => $at->id, 'starts_on' => $from->format('Y-m-d')]);
    }
}
