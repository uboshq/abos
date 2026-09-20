<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Governance\Services\WhatIsKeptHowLong;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোন কাগজ কতদিন থাকে — একটা পর্দায়, আর সেটা সত্যি বলে।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ফিন্যান্স মানচিত্রের §২৮। ⓘ প্রশ্নটা ওঠে নিরীক্ষক বা কর অফিস এলে:
 * "তিন বছর আগের বিলটা দেখান"। উত্তর না জানা থাকলে খোঁজাখুঁজি শুরু হয়,
 * আর "সব তো আছেই" বলাটাই সবচেয়ে বিপজ্জনক উত্তর।
 *
 * ⛔ পর্দাটা নিয়ম বসায় না — কোডে যা ঘটে তাই দেখায়। লেখা নীতি আর আসল
 * আচরণ আলাদা হলে নীতিটাই সবচেয়ে বিপজ্জনক কাগজ।
 */
final class NobodyCouldSayHowLongAPaperIsKeptTest extends TestCase
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

    public function test_the_page_names_the_ledger_and_the_backups_apart(): void
    {
        $html = $this->get(route('governance.retention.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-kept="ledger"', $html, 'খতিয়ানের সারিটাই নেই।');
        $this->assertStringContainsString('data-kept="backups"', $html, 'ব্যাকআপের সারিটা নেই।');

        /* ⚠️ কম্পোনেন্ট কম্পাইল না হলে পাতা তবু ২০০ দেয় */
        $this->assertStringNotContainsString('<x-ui.', $html, 'একটা কম্পোনেন্ট লেখা হিসেবে ছাপা হয়েছে।');
    }

    /** ⭐ সবচেয়ে দুর্বল জায়গাটা পর্দায় আলাদা করে বলা — খাতা চিরকাল, ব্যাকআপ নয়। */
    public function test_it_says_the_backup_window_out_loud(): void
    {
        $days = app(WhatIsKeptHowLong::class)->backupDays();

        $this->get(route('governance.retention.index'))
            ->assertOk()
            ->assertSee((string) $days, false);

        $this->assertGreaterThan(0, $days, 'ব্যাকআপের সীমাটা শূন্য — তাহলে সংখ্যাটা অর্থহীন।');
    }

    /**
     * ⛔ যে সারিগুলো "চিরকাল" বলা হয়, সেগুলো সত্যিই কেউ মোছে না।
     *
     * ── ⚠️ এই পরীক্ষাটা একটা বৃত্ত ছিল, ২০ সেপ্টেম্বর ২০২৬ পর্যন্ত ──────
     * আগে এখানে `kept === FOREVER` দিয়ে ছেঁকে `by === 'nobody'` মেলানো হত।
     * ⛔ কিন্তু সেবার কোডে `by`-টা **ঐ একই লাইনে** `kept` থেকেই বানানো হত,
     * তাই দাবিটা কখনো লাল হতে পারত না — খতিয়ান ছাঁটার কাজ বসালেও না।
     *
     * ⭐ এখন মাপটা কোডের **বাইরে** থেকে: শিডিউলে সত্যিই যে কাজগুলো সারি
     * মোছে, সেগুলোর সাথে মেলানো হয়।
     */
    public function test_the_forever_rows_are_not_pruned_by_the_schedule(): void
    {
        $forever = collect(app(WhatIsKeptHowLong::class)->all())
            ->where('kept', WhatIsKeptHowLong::FOREVER);

        $this->assertGreaterThan(3, $forever->count(), 'চিরকাল থাকা সারির তালিকাটা সন্দেহজনকভাবে ছোট।');

        /* ⓘ যে কমান্ডগুলো সারি ছাঁটে — শিডিউলে এগুলোই বসানো আছে। */
        $pruners = ['abos:backup-due', 'submitted-forms-prune', 'abos:reports-due'];

        foreach ($forever as $row) {
            $this->assertSame('nobody', $row['by'], $row['key'].': "চিরকাল" বলা হচ্ছে, অথচ কেউ মোছে।');
        }

        /* ⛔ আর উল্টো দিকটা: যা মোছা হয়, তার প্রতিটাই তালিকায় আছে তো? */
        $scheduled = collect(app(WhatIsKeptHowLong::class)->all())
            ->where('by', 'schedule')
            ->pluck('key');

        $this->assertSame(
            count($pruners),
            $scheduled->count(),
            'শিডিউলে '.count($pruners).'টা কাজ সারি মোছে, অথচ পর্দায় '.$scheduled->count()
                .'টা সারি "মোছা হয়" বলছে — কোনো একটা টেবিল চুপচাপ মুছছে।'
        );
    }

    /**
     * ⛔ সংখ্যাটা এই কোম্পানির, সবার মিলিয়ে নয়।
     *
     * ⚠️ `DB::table()->count()` গ্লোবাল স্কোপ মানে না, তাই এক কোম্পানির
     * লোক অন্য কোম্পানির ব্যবসার **আকার** পড়ে ফেলতে পারতেন।
     */
    public function test_the_counts_belong_to_this_company_only(): void
    {
        $mine = collect(app(WhatIsKeptHowLong::class)->all())->firstWhere('key', 'vouchers');

        $everyones = (int) \Illuminate\Support\Facades\DB::table('vouchers')->count();
        $thisOne = (int) \Illuminate\Support\Facades\DB::table('vouchers')
            ->where('company_id', \App\Core\Support\CompanyContext::id())
            ->count();

        /* ⓘ ডেমোতে দ্বিতীয় কোম্পানির সারি না থাকলে মাপটা কিছুই প্রমাণ করে না। */
        if ($everyones === $thisOne) {
            $this->markTestSkipped('ডেমোতে অন্য কোম্পানির ভাউচার নেই — তুলনাটা অর্থহীন।');
        }

        $this->assertSame($thisOne, $mine['rows'], 'গণনায় অন্য কোম্পানির সারিও উঠে এসেছে।');
    }
}
