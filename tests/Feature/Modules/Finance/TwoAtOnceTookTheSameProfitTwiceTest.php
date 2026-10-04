<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দুইজন একসাথে, আর একই ঘোষিত লাভ দুইবার — অডিট গ১৪, ম২৮ (৪ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 *   • [[ProfitDistribution::capitalise()]] বাকিটা গুনত লেনদেনের বাইরে, তালা ছাড়া — দুই চাপে মূলধন দ্বিগুণ।
 *   • [[WithdrawalService::post()]] লাভের ভাগের বাকিটা দেখত লেনদেনের বাইরে — দুইটা তোলা একই বাকি পেত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * দুই পথই প্রদেয় মুনাফার খাতে (২১৯০) তালা দিয়ে বাকিটা লেনদেনের ভিতরে আবার গোনে।
 * [[TwoAtOnceBrokeAFinanceCeilingTest]]-এর ছাঁচ: সত্যিকারের দুই সংযোগ, অন্যজন আমাদের লেনদেন শুরুর মুহূর্তে।
 */
final class TwoAtOnceTookTheSameProfitTwiceTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'finance_twice';

    private Person $partner;

    private ?string $other = null;

    private string $declared = '0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        // ⓘ একজনই অংশীদার — পুরো ঘোষণা তাঁর
        CapitalEntry::query()->delete();
        $this->partner = Person::query()->create(['code' => 'TWICE', 'name_en' => 'Twice', 'name_bn' => 'Twice', 'is_active' => true]);

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-TWICE',
            'person_id' => $this->partner->id,
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(),
            'amount' => '500000',
            'status' => CapitalEntry::POSTED,
        ]);

        $this->money('200000');

        app(ProfitDistribution::class)->declare(['profit' => '50000', 'trx_date' => now()->toDateString()]);
        $this->declared = app(ProfitDistribution::class)->outstandingFor($this->partner->id);

        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');

        auth()->user()?->loadMissing(['roles', 'permissions']);
        auth()->user()?->getAllPermissions();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::purge(self::SECOND);

        parent::tearDown();
    }

    public function test_a_capitalisation_made_meanwhile_is_seen_before_ours(): void
    {
        $this->atOurBegin(fn () => app(ProfitDistribution::class)->capitalise(['trx_date' => now()->toDateString()]));

        $this->tryTo(fn () => app(ProfitDistribution::class)->capitalise(['trx_date' => now()->toDateString()]));

        $this->assertSame('done', $this->other, 'দৃশ্যটাই বানানো যায়নি — অন্যজনের মূলধনে-নেওয়া হয়নি: '.var_export($this->other, true));

        $capitalised = (string) CapitalEntry::query()->where('in_kind', CapitalEntry::PROFIT)->posted()->sum('amount');

        $this->assertLessThanOrEqual(0, bccomp($capitalised, $this->declared, 4),
            "⛔ ঘোষিত লাভ {$this->declared}, অথচ মূলধনে উঠল {$capitalised} — একই টাকা দুইবার।");
    }

    public function test_a_profit_withdrawal_made_meanwhile_is_seen_before_ours(): void
    {
        $ours = $this->ask();
        $theirs = $this->ask();

        /*
         * ⚠️ অন্যজনের তোলাটা **তাঁর নিজের সংযোগে** পড়া — ৪ অক্টোবর ২০২৬। Eloquent মডেল যে সংযোগে পড়া হয় সেটা মনে রাখে;
         * `$theirs` আমাদের সংযোগে পড়া ছিল, তাই তাঁর লেখা আমাদের খোলা লেনদেনে যেত আর তাঁর বাকি কাজ নিজের সারিতেই
         * আটকে "1205 Lock wait timeout" দিত — দৃশ্যটাই তৈরি হত না। এখানে নতুন করে পড়া হয়, দ্বিতীয় সংযোগ তখন ডিফল্ট।
         */
        $this->atOurBegin(fn () => app(WithdrawalService::class)->post(Withdrawal::query()->findOrFail($theirs->id), $this->cash()));

        $this->tryTo(fn () => app(WithdrawalService::class)->post($ours, $this->cash()));

        $this->assertSame('done', $this->other, 'দৃশ্যটাই বানানো যায়নি — অন্যজনের তোলা হয়নি: '.var_export($this->other, true));

        $taken = (string) Withdrawal::query()->where('status', DocumentStatus::CONFIRMED)->sum('amount');

        $this->assertLessThanOrEqual(0, bccomp($taken, $this->declared, 4),
            "⛔ ঘোষিত লাভ {$this->declared}, অথচ তোলা হলো {$taken} — প্রদেয় মুনাফা ঋণাত্মক।");
    }

    // ── দৌড় ────────────────────────────────────────────────────────────

    private function atOurBegin(callable $other): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$armed, $main, $other): void {
            if (! $armed || $event->connectionName !== $main) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                $other();
                $this->other = 'done';
            } catch (QueryException|ValidationException $e) {
                $this->other = class_basename($e).': '.mb_substr($e->getMessage(), 0, 160);
            } finally {
                DB::setDefaultConnection($main);
            }
        });
    }

    private function tryTo(callable $ours): void
    {
        try {
            $ours();
        } catch (ValidationException) {
            // দুইজনের একজন ফিরবেন — কোনজন, সেটা প্রশ্ন নয়
        }
    }

    private function ask(): Withdrawal
    {
        return app(WithdrawalService::class)->request([
            'person_id' => $this->partner->id,
            'amount' => $this->declared,
            'kind' => Withdrawal::PROFIT_SHARE,
            'trx_date' => now()->toDateString(),
        ]);
    }

    /** নগদে টাকা আর সঞ্চিত মুনাফা — ঘোষণার জন্য, পোস্টিং ইঞ্জিন দিয়েই */
    private function money(string $amount): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'test:earned',
            sourceId: 1,
            trxDate: now()->subDays(2)->toDateString(),
            lines: [
                ['account_id' => $this->cash()->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::RETAINED_EARNINGS)->id, 'debit' => '0', 'credit' => $amount],
            ],
        );
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
