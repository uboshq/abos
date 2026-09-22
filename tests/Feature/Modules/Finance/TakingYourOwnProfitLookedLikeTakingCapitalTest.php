<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিজের লাভ তোলাটা দেখাত মূলধন প্রত্যাহারের মতো।
 *
 * ── ⛔ যা ভাঙা ছিল, ২২ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * উত্তোলনে [[Withdrawal::PROFIT_SHARE]] ধরনটা **আগে থেকেই ছিল**, আর
 * পর্দায় বাছাও যেত। ⚠️ কিন্তু দাখিলা সবসময় উত্তোলন খাতে (`DRAWINGS`)
 * যেত — অর্থাৎ ধরনটা একটা লেবেল ছিল, খাতায় কোনো পার্থক্য করত না।
 *
 * ⓘ এটাই এই রিপোর চেনা আকৃতি: ঘরটা আছে, নামটা আছে, কেউ ওটা **পড়ে
 * না**, আর কিছুই ভাঙে না।
 *
 * ── ⚠️ ফলটা দুই দিকেই ভুল ─────────────────────────────────────────
 * ⛔ এক দিকে **প্রদেয় মুনাফার জের কমত না** — খাতা বলত টাকাটা এখনো
 * দিতে বাকি, অথচ দেওয়া হয়ে গেছে।
 * ⛔ অন্য দিকে **মূলধন কমত** — খাতা বলত মালিক ব্যবসা থেকে পুঁজি
 * সরাচ্ছেন, অথচ তিনি নিজের ঘোষিত লাভ নিচ্ছেন।
 *
 * ⓘ মালিকের নকশাটা ঠিক এই পার্থক্যের উপরেই দাঁড়ানো — *"টাকাটা তুলে
 * নেবেন"*, আর সেটা দায় শোধ, মূলধন কমানো নয়।
 */
final class TakingYourOwnProfitLookedLikeTakingCapitalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Person $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany((int) $company->id);
        $this->be($this->owner);

        // ⚠️ একজন অংশীদার ছাড়া ভাগ বসে না, আর দাবিগুলো কিছুই মাপত না
        $this->partner = Person::query()->create([
            'code' => 'WDR-P',
            'name_en' => 'Profit partner',
            'name_bn' => 'Profit partner',
            'is_active' => true,
        ]);

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-WDR-P',
            'person_id' => $this->partner->id,
            'contributor_type' => CapitalEntry::CONTRIBUTION,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(),
            'amount' => '500000',
            'status' => CapitalEntry::POSTED,
        ]);

        app(ProfitDistribution::class)->declare([
            'trx_date' => now()->subDays(2)->toDateString(),
            'profit' => '40000',
        ]);
    }

    /**
     * ⭐ লাভের ভাগ তুললে দেনা কমে, মূলধন নয়।
     */
    public function test_taking_declared_profit_settles_the_debt(): void
    {
        $capitalBefore = $this->balance(StandardChart::DRAWINGS);

        $this->take(Withdrawal::PROFIT_SHARE, '15000');

        $payable = $this->movement(StandardChart::PROFIT_PAYABLE);

        $this->assertSame(0, bccomp($payable['debit'], '15000', 4), implode("\n", [
            'লাভের ভাগ তোলায় প্রদেয় মুনাফা থেকে ১৫,০০০ ডেবিট হয়নি — হয়েছে '.$payable['debit'].'।',
            '',
            '⛔ তাহলে খাতা বলবে টাকাটা এখনো দিতে বাকি, অথচ দেওয়া হয়ে গেছে।',
        ]));

        $this->assertSame(0, bccomp($this->balance(StandardChart::DRAWINGS), $capitalBefore, 4),
            implode("\n", [
                'লাভ তোলায় উত্তোলন খাত নড়েছে।',
                '',
                '⛔ খাতা বলবে মালিক পুঁজি সরাচ্ছেন, অথচ তিনি নিজের লাভ নিচ্ছেন।',
            ]));
    }

    /**
     * ⭐ বাকি দুই ধরন আগের মতোই — মূলধনেই বসে।
     *
     * ── ⚠️ কেন আলাদা দাবি ───────────────────────────────────────────
     * উপরেরটা কেবল লাভের ভাগ মাপে। ⛔ কেউ যদি **সব** উত্তোলন প্রদেয়
     * মুনাফায় পাঠিয়ে দেয়, ওটা সবুজই থাকত — আর তখন মালিকের নিজের খরচ
     * বা বেতনও দায় শোধ বলে দেখাত, অথচ ওগুলো সত্যিই মূলধন কমায়।
     */
    public function test_a_drawing_still_comes_out_of_capital(): void
    {
        $payableBefore = $this->movement(StandardChart::PROFIT_PAYABLE)['debit'];

        $this->take(Withdrawal::DRAWING, '9000');

        $drawings = $this->movement(StandardChart::DRAWINGS);

        $this->assertSame(0, bccomp($drawings['debit'], '9000', 4),
            'নিজের খরচ তোলায় উত্তোলন খাতে ৯,০০০ ডেবিট হয়নি — হয়েছে '.$drawings['debit'].'।');

        $this->assertSame(0, bccomp($this->movement(StandardChart::PROFIT_PAYABLE)['debit'], $payableBefore, 4),
            implode("\n", [
                'নিজের খরচ তোলায় প্রদেয় মুনাফা নড়েছে।',
                '',
                '⛔ ওটা দায় শোধ নয় — মালিক সত্যিই পুঁজি সরাচ্ছেন।',
            ]));
    }

    // ── সহায়ক ───────────────────────────────────────────────────────

    private function take(string $kind, string $amount): void
    {
        $withdrawal = app(WithdrawalService::class)->request([
            'person_id' => $this->partner->id,
            'kind' => $kind,
            'in_kind' => 'cash',
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'reason' => 'test',
        ]);

        /*
         * ⓘ অবস্থা জোর করে বদলানো হয় না। ⚠️ একবার `CONFIRMED` বসানো
         * হয়েছিল, আর পাহারা বলল *"আগেই খাতায় বসেছে"* — এই
         * মডেলে `CONFIRMED` মানে **পোস্ট হয়েছে**, অনুমোদিত নয়।
         *
         * ⓘ অনুমোদন ঝুলে থাকলে `post()` নিজেই আটকাবে, আর তখন
         * এই পরীক্ষাটাই লাল হবে — সেটাই চাই।
         */
        app(WithdrawalService::class)->post(
            $withdrawal->fresh(),
            /*
             * ⛔ `CASH_IN_HAND` ('1101') একটা **মাথা**, পোস্টযোগ্য খাত নয়।
             * ⓘ ফাঁদটা CLAUDE.md-তেও নাম ধরে লেখা, আর আমি তবু একবার
             * পড়েছি — পাহারা বলল *"এটা একটা মাথা, খাত নয়"*।
             */
            Account::query()->money()->where('is_group', false)->firstOrFail(),
        );
    }

    /** @return array{debit: string, credit: string} */
    private function movement(string $code): array
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return [
            'debit' => (string) (LedgerEntry::query()->where('account_id', $account->id)->sum('debit') ?: '0'),
            'credit' => (string) (LedgerEntry::query()->where('account_id', $account->id)->sum('credit') ?: '0'),
        ];
    }

    private function balance(string $code): string
    {
        $m = $this->movement($code);

        return bcsub($m['debit'], $m['credit'], 4);
    }
}
