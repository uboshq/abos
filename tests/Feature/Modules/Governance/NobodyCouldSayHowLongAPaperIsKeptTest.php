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
     * ⓘ মাপটা তালিকার বিরুদ্ধেই: কোনো দিন কেউ খতিয়ান ছাঁটার কাজ বসালে
     * এই দাবিটা লাল হবে, আর তখন পর্দার লেখাটাও বদলাতে হবে — নাহলে পর্দা
     * মিথ্যা বলত।
     */
    public function test_the_forever_rows_are_not_pruned_by_the_schedule(): void
    {
        $forever = collect(app(WhatIsKeptHowLong::class)->all())
            ->where('kept', WhatIsKeptHowLong::FOREVER);

        $this->assertGreaterThan(3, $forever->count(), 'চিরকাল থাকা সারির তালিকাটা সন্দেহজনকভাবে ছোট।');

        foreach ($forever as $row) {
            $this->assertSame('nobody', $row['by'], $row['key'].': "চিরকাল" বলা হচ্ছে, অথচ কেউ মোছে।');
        }
    }
}
