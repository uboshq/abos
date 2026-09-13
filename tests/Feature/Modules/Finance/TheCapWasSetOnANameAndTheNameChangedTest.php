<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সীমা বসেছিল একটা নামে, আর পরের বার নামটা একটু আলাদা লেখা হলো।
 *
 * ── কী ভাঙা ছিল, ১৩ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * মালিক জিজ্ঞেস করেছিলেন *"একই মালিক আবার বিনিয়োগ করলে আবার নাম লিখতে
 * হবে?"* — আর সেই প্রশ্নের পিছনে **তিনটা আলাদা নীরব ব্যর্থতা** বেরিয়ে
 * এসেছে। তিনটাই টাকার সংখ্যা, আর তিনটাই কোনো ত্রুটি ছাড়া ভুল উত্তর দিত।
 *
 * ⚠️ এই ফাইলে তিনটা **আলাদা দাবিতে** বাঁধা, আর সেটা ইচ্ছাকৃত: একটা
 * পরীক্ষায় তিনটা ধরলে একটা সারালে বাকি দুইটা চাপা পড়ে যেত, আর কেউ
 * জানতেও পারত না কোনটা আর পাহারায় নেই।
 *
 *   ১। সীমা **খুঁজেই পাওয়া যেত না** → চুপচাপ কিছুই আটকাত না
 *   ২। সীমা পাওয়া গেলেও **এই মাসে কত তোলা হয়েছে** কম গোনা হত
 *   ৩। **নিট মূলধন** গোনা হত খতিয়ানের বিবরণে নাম খুঁজে (substring)
 *
 * ── কেন দুই নম্বরটা এক নম্বরের চেয়ে খারাপ ────────────────────────────
 * এক নম্বরে সীমা **একেবারেই কাজ করে না**, যা অন্তত ধারাবাহিক — কেউ
 * একদিন খেয়াল করেন। দুই নম্বরে সীমা **কাজ করছে বলে মনে হয়**: পর্দা
 * বলে "সীমার ভেতরে আছেন", বার্তায় "বাকি আছে এতটা" দেখায়, আর সংখ্যাটা
 * মিথ্যা। মানুষ ঠিক ওই সংখ্যা দেখে সিদ্ধান্ত নেন।
 */
class TheCapWasSetOnANameAndTheNameChangedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /**
     * ⭐ ১ · সীমাটা সত্যিই আটকায় — নামের বানান যা-ই হোক।
     *
     * ── পুরনো নকশায় এটা কেন লাল হত ───────────────────────────────────
     * সীমা বসত `contributor_name = 'Al Amin'` সারিতে, আর উত্তোলন লেখা
     * হত `'Al-Amin'` নামে। `assertWithinCap()` হুবহু নাম মেলাত, তাই
     * `$cap` হত `null` আর পদ্ধতিটা চুপচাপ `return` করত।
     *
     * ⓘ এখন পরিচয়টা একটা সারি, তাই "বানান" বলে কিছুই নেই — সীমা আর
     * উত্তোলন দুইটাই একই `person_id`-তে বাঁধা।
     */
    public function test_the_cap_actually_stops_the_money(): void
    {
        $person = $this->person('Al Amin');

        app(WithdrawalService::class)->setCap($person->id, '20000');

        // সীমার ভেতরে — এটা যেতে পারবে
        $this->take($person, '15000');

        $this->expectException(ValidationException::class);

        // সীমা পেরিয়ে যায় — এখানেই থামতে হবে
        $this->take($person, '10000');
    }

    /**
     * ⭐ ২ · "এই মাসে কত তোলা হয়েছে" সংখ্যাটা একটাও সারি বাদ দেয় না।
     *
     * ── এটাই সবচেয়ে খারাপ রূপ ─────────────────────────────────────────
     * পুরনো নকশায় আগের উত্তোলনগুলো অন্য বানানে থাকলে `$already` কম
     * আসত, তাই সীমা বসত ভুল মোটের উপর — আর কেউ সীমার **দ্বিগুণ** তুলে
     * ফেলতে পারতেন, অথচ পর্দা বলত সব ঠিক আছে।
     *
     * ⚠️ দাবিটা আলাদা রাখা হয়েছে এক নম্বর থেকে: এক নম্বর সারালেও (সীমা
     * পাওয়া গেল) এটা ভাঙা থাকতে পারত, কারণ দুইটা আলাদা কোয়েরি।
     */
    public function test_what_was_already_taken_this_month_counts_every_row(): void
    {
        $person = $this->person('Karim');

        // তিনটা উত্তোলন, একই মানুষ — মোট ১৮,০০০
        $this->take($person, '6000');
        $this->take($person, '6000');
        $this->take($person, '6000');

        app(WithdrawalService::class)->setCap($person->id, '20000');

        /*
         * এখন সীমা ২০,০০০, আর ইতিমধ্যে গেছে ১৮,০০০ — বাকি ২,০০০।
         *
         * ⓘ সেটআপটা সত্যিই যা দাবি করে তা-ই করেছে কি না, আগে সেটা দেখা:
         * নাহলে নিচের দাবিটা শূন্য উত্তোলনের উপর চলত আর চিরকাল সবুজ হত।
         */
        $this->assertSame(3, Withdrawal::query()->where('person_id', $person->id)->count(),
            'সেটআপেই তিনটা উত্তোলন বসেনি — নিচের দাবিটা তখন কিছুই প্রমাণ করে না।');

        $this->expectException(ValidationException::class);

        // ৩,০০০ চাইলে মোট হয় ২১,০০০ — সীমা পেরিয়ে যায়
        $this->take($person, '3000');
    }

    /**
     * ⭐ ৩ · নিট মূলধন — এক মানুষের টাকা আরেকজনের হিসাবে যায় না।
     *
     * ── পুরনো নকশায় এটা কেন লাল হত ───────────────────────────────────
     * `CapitalService::withdrawnBy()` খতিয়ানের বিবরণে `LIKE '%নাম%'`
     * করত। তাই "রহিম" নামের অংশীদারের নিটে **"আব্দুর রহিম"-এর উত্তোলনও**
     * যোগ হয়ে যেত — এক মালিকের টাকা আরেকজনের হিসাবে, আর দুইজনেরই
     * অংশ % ভুল।
     *
     * ⓘ নামগুলো ইচ্ছাকৃতভাবে এমন যে একটা অন্যটার ভেতরে আছে — substring
     * ফিরে এলে এই দাবিটাই প্রথমে ভাঙবে।
     */
    public function test_one_persons_withdrawal_never_lands_in_anothers_net(): void
    {
        $rahim = $this->person('Rahim');
        $abdurRahim = $this->person('Abdur Rahim');

        $this->contribute($rahim, '100000');
        $this->contribute($abdurRahim, '100000');

        // কেবল আব্দুর রহিম তুলেছেন
        $this->confirm($this->take($abdurRahim, '30000'));

        $positions = collect(app(CapitalService::class)->positions())->keyBy('name');

        $this->assertNotEmpty($positions, 'কোনো অবস্থানই আসেনি — নিচের দাবিগুলো তখন শূন্যের উপর।');

        $this->assertSame(0, bccomp((string) $positions['Rahim']['withdrawn'], '0', 4),
            'রহিম কিছুই তোলেননি, অথচ তাঁর হিসাবে উত্তোলন বসেছে — '
            .'বিবরণে substring খোঁজা ফিরে এসেছে কি?');

        $this->assertSame(0, bccomp((string) $positions['Abdur Rahim']['withdrawn'], '30000', 4),
            'যিনি সত্যিই তুলেছেন, তাঁর হিসাবে টাকাটা বসেনি।');
    }

    /**
     * ⚠️ ৪ · জানা সীমা — জাবেদা দিয়ে সরাসরি বসানো টাকা কারো নিটে যায় না।
     *
     * ── কেন এই দাবিটা আছে ───────────────────────────────────────────
     * `withdrawnBy()` এখন **উত্তোলনের সারি** ধরে গোনে, খতিয়ান ধরে নয়।
     * তাই উত্তোলনের পর্দা দিয়ে না গিয়ে কেউ সোজা জাবেদায় ৩২০০ খাতে টাকা
     * বসালে সেটা কোনো মানুষের নামে বসে না।
     *
     * ⭐ এটা সচেতন সিদ্ধান্ত, আর দাবিটা লেখা হয়েছে **ঠিক সেই কারণেই**:
     * ছয় মাস পরে কেউ এটা "বাগ" ভেবে আবার বিবরণ-খোঁজা ফিরিয়ে আনতে
     * পারেন। আগের আচরণে সারিটা যোগ হত ঠিকই, কিন্তু substring মিলিয়ে —
     * অর্থাৎ প্রায়ই ভুল মানুষের নিটে। **ভুল দায় দেওয়ার চেয়ে দায় না
     * দেওয়া ভালো**, কারণ একটা ভুল সংখ্যা শূন্যের চেয়ে বিপজ্জনক।
     *
     * পরের জন যদি সচেতনভাবে এটা বদলাতে চান, তাঁকে এই দাবিটা মুছতে হবে —
     * আর তখন সিদ্ধান্তটা দৃশ্যমান হবে, নীরব নয়।
     */
    public function test_a_journal_posted_straight_to_drawings_is_nobodys(): void
    {
        $person = $this->person('Shamim');
        $this->contribute($person, '50000');

        $drawings = StandardChart::find(StandardChart::DRAWINGS);
        $this->assertNotNull($drawings, 'উত্তোলনের খাতটাই নেই — দাবিটা তখন অর্থহীন।');

        /*
         * একটা সত্যিকারের জাবেদা — উত্তোলনের পর্দা দিয়ে নয়।
         *
         * ── কেন কাঁচা `ledger_entries` insert নয় ─────────────────────
         * প্রথম খসড়ায় হাতে একটা খতিয়ান-সারি বসানো হয়েছিল, আর সেটা
         * ভেঙেছে: `financial_year_id` বাধ্যতামূলক, আর টেস্টটা সেটা
         * জানত না। ⚠️ ভুলটা কেবল একটা কলাম ভোলা নয় — কাঁচা insert
         * **দরকারি কলামের জ্ঞান দুই জায়গায় রাখে**, তাই ছক বদলালে
         * টেস্টটা ভাঙত অথচ প্রোডাকশন কোড ঠিকই থাকত।
         *
         * ⭐ আর গুরুত্বপূর্ণ: হাতে বসানো সারিটা আসল জাবেদার প্রতিনিধিত্বই
         * করত না। দাবিটার কথা হলো *"কেউ পর্দা এড়িয়ে সরাসরি ৩২০০-এ টাকা
         * বসালে সেটা কারো নিটে যাবে না"* — তাই ঘটনাটা আসল ইঞ্জিন দিয়েই
         * ঘটানো উচিত, নাহলে দাবিটা যা মাপে বলে দাবি করে তা মাপে না।
         *
         * ⓘ বিবরণে নামটা ইচ্ছাকৃতভাবে লেখা — হুবহু সেই আকৃতি যেটা পুরনো
         * `LIKE '%নাম%'` ধরে ফেলত।
         */
        $journal = app(VoucherService::class)->create(
            [
                'type' => Voucher::JOURNAL,
                'trx_date' => now()->toDateString(),
                'narration' => 'Shamim নিজে নিয়ে গেছেন',
            ],
            [
                ['account_id' => $drawings->id, 'debit' => '7000', 'credit' => '0'],
                ['account_id' => $this->cash()->id, 'debit' => '0', 'credit' => '7000'],
            ],
        );

        app(VoucherService::class)->post($journal);

        // সেটআপটা সত্যিই খাতায় বসেছে — নাহলে নিচের দাবিটা শূন্যের উপর
        $this->assertTrue($journal->fresh()->isPosted(),
            'জাবেদাটা পোস্টই হয়নি — দাবিটা তখন কিছুই প্রমাণ করে না।');

        $positions = collect(app(CapitalService::class)->positions())->keyBy('name');

        $this->assertArrayHasKey('Shamim', $positions->all(),
            'অবস্থানের তালিকায় মানুষটাই নেই — দাবিটা তখন কিছুই মাপছে না।');

        $this->assertSame(0, bccomp((string) $positions['Shamim']['withdrawn'], '0', 4),
            'জাবেদার সারিটা কারো নিটে বসে গেছে — বিবরণ ধরে গোনা ফিরে এসেছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'P-'.mb_substr(md5($name), 0, 6),
            'name_en' => $name,
        ]);
    }

    private function take(Person $person, string $amount): Withdrawal
    {
        return app(WithdrawalService::class)->request([
            'person_id' => $person->id,
            'amount' => $amount,
            'trx_date' => now()->toDateString(),
        ]);
    }

    /**
     * খাতায় বসানো — নাহলে `posted()` ছাঁকনিতে সারিটা পড়ে না।
     *
     * ⚠️ নামটা `post()` নয়: `TestCase::post()` HTTP অনুরোধ পাঠায়, আর
     * একই নামে লিখলে সেটাকে ঢেকে দিত। PHPStan ধরেছে।
     */
    private function confirm(Withdrawal $withdrawal): Withdrawal
    {
        return app(WithdrawalService::class)->post($withdrawal, $this->cash());
    }

    private function contribute(Person $person, string $amount): CapitalEntry
    {
        $entry = app(CapitalService::class)->record([
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
        ]);

        return app(CapitalService::class)->post($entry, $this->cash());
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->firstOrFail();
    }
}
