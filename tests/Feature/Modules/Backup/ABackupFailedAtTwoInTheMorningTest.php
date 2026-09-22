<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification as Bell;
use App\Models\User;
use App\Modules\Backup\Services\BackupRunner;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * রাত দুইটায় ব্যাকআপ ব্যর্থ হলো, আর কেউ জানল না।
 *
 * ── ⛔ ২২ সেপ্টেম্বর ২০২৬ পর্যন্ত যা সত্যি ছিল ──────────────────────
 * ব্যর্থতাটা `bak_runs`-এ একটা লাল সারি হয়ে বসত, আর সেখানেই শেষ।
 * ⚠️ পরদিন সকালে কিছুই আলাদা দেখায় না — অ্যাপ স্বাভাবিক চলে, বিল
 * কাটা হয়, মাল ছাড়া হয় — কেবল ঐ রাতের কপিটা নেই।
 *
 * ⓘ জানা যেত কেবল সেদিন, যেদিন কপিটা ফেরানোর দরকার পড়ত। ⛔ আর তখন
 * প্রশ্নটা আর *"কেন ব্যর্থ হলো"* নয়, *"কত দিনের কাজ গেল"*।
 *
 * ── ⚠️ আর এই পর্দাটা মানুষ খোলে ঠিক ঐ দিনটায় ───────────────────────
 * ব্যাকআপের তালিকা কেউ রোজ দেখে না — ওটা খোলা হয় বিপদের দিনে। ⓘ তাই
 * "পর্দায় তো লেখা আছে" কথাটা এখানে কোনো উত্তর নয়।
 */
final class ABackupFailedAtTwoInTheMorningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /**
     * ⭐ রাতের ব্যাকআপ ভেঙে পড়লে খবরটা মানুষের কাছে যায়।
     */
    public function test_a_failed_night_tells_somebody(): void
    {
        app(BackupRunner::class)->recordFailure(
            null,
            new RuntimeException("Access denied … to database 'univerbd_abos_verify'"),
            whileVerifying: true,
        );

        CompanyContext::set($this->company->id);

        $this->assertGreaterThan(0, $this->notices(), implode("\n", [
            '⛔ ব্যাকআপ ব্যর্থ হলো, আর কাউকে কিছু বলা হলো না।',
            '',
            '⚠️ খাতায় একটা লাল সারি পড়ল, কিন্তু সারিটা কেউ পড়ে না —',
            'ব্যাকআপের পর্দা মানুষ খোলে ঠিক সেদিন, যেদিন ব্যাকআপটা লাগে।',
        ]));
    }

    /**
     * ⭐ আর কারণটা খবরের ভিতরেই থাকে, হুবহু।
     *
     * ⛔ *"ব্যাকআপ ব্যর্থ হয়েছে"* — এইটুকু বললে মানুষকে সার্ভারে ঢুকে
     * লগ পড়তে হত। ⚠️ যে খবর দুশ্চিন্তা দেয় কিন্তু উত্তর দেয় না, সেটা
     * নীরবতার চেয়ে সামান্যই ভালো।
     */
    public function test_the_reason_travels_with_the_news(): void
    {
        app(BackupRunner::class)->recordFailure(
            null,
            new RuntimeException('mysqldump: got error 1045'),
            whileVerifying: false,
        );

        CompanyContext::set($this->company->id);

        $body = (string) Bell::query()
            ->withoutGlobalScope('company')
            ->where('type', 'backup.failed')
            ->value('body');

        $this->assertStringContainsString('1045', $body,
            '⛔ কারণটা খবরের সাথে যায়নি — তাহলে জানতে হলে সার্ভারে ঢুকে লগ পড়তে হবে।');
    }

    /**
     * ⭐ আর খবরটা যায় কেবল তাঁদের কাছে, যাঁরা ব্যাকআপ দেখতে পারেন।
     *
     * ⛔ সবাইকে পাঠালে বিক্রয়কর্মীর ঘণ্টায় এমন একটা খবর বসত যেটা নিয়ে
     * তাঁর কিছুই করার নেই — আর [[NotificationService]]-এর নিজের নিয়মই
     * সেটা বারণ করে।
     */
    public function test_only_those_who_can_see_backups_are_told(): void
    {
        app(BackupRunner::class)->recordFailure(
            null,
            new RuntimeException('disk full'),
            whileVerifying: false,
        );

        CompanyContext::set($this->company->id);

        $told = Bell::query()
            ->withoutGlobalScope('company')
            ->where('type', 'backup.failed')
            ->pluck('user_id')
            ->unique();

        $this->assertNotEmpty($told, 'ⓘ কেউ খবর পায়নি — আগের দাবিটাই আগে দেখুন।');

        foreach ($told as $id) {
            $user = User::query()->withoutGlobalScope('company')->findOrFail($id);

            $this->assertTrue($user->can('backup.view'), implode("\n", [
                "⛔ {$user->name} ব্যাকআপ দেখতেই পারেন না, তবু খবরটা পেয়েছেন।",
                '',
                '⚠️ যে খবর নিয়ে মানুষের কিছু করার নেই, সেটা ঘণ্টার সংখ্যাটা',
                'বাড়ায় আর বাকি সব খবরের দাম কমায়।',
            ]));
        }
    }

    /**
     * ⭐ একটা ব্যর্থতা, একটা খবর — কোম্পানি যতগুলোই হোক।
     *
     * ── ⛔ নিজের কোডেই ধরা, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────
     * `recordFailure()` প্রতিটা কোম্পানির জন্য আলাদা সারি লেখে, কিন্তু
     * ডাম্পটা **একটাই** — একবার ব্যর্থ হয়েছে, তিনবার নয়।
     *
     * ⚠️ ছাঁকনিটা না থাকলে তিন কোম্পানিতে থাকা একজন মালিক একই রাতের
     * ব্যর্থতার **তিনটা চিঠি** পেতেন। ⓘ আর ওটা কেবল বিরক্তিকর নয়:
     * তৃতীয় রাতেই তিনি ব্যাকআপের চিঠি দেখা ছেড়ে দিতেন, আর তারপর
     * যেদিন সত্যিই সব হারাত সেদিনের চিঠিটাও অদেখা থাকত।
     */
    public function test_one_failure_is_one_piece_of_news(): void
    {
        $this->assertGreaterThan(1, Company::query()->count(),
            'একটার বেশি কোম্পানি না থাকলে এই দাবিটা কিছুই মাপে না।');

        app(BackupRunner::class)->recordFailure(
            null,
            new RuntimeException('disk full'),
            whileVerifying: false,
        );

        CompanyContext::set($this->company->id);

        $perPerson = Bell::query()
            ->withoutGlobalScope('company')
            ->where('type', 'backup.failed')
            ->get()
            ->countBy('user_id');

        $this->assertEmpty($perPerson->filter(fn (int $n) => $n > 1)->all(), implode("\n", [
            '⛔ একই রাতের একটা ব্যর্থতার জন্য কেউ একাধিক খবর পেয়েছেন।',
            '',
            '⚠️ ডাম্পটা একটাই — কোম্পানি ধরে সারি লেখা হয়, কিন্তু মানুষটা',
            'একজনই। ⓘ পুনরাবৃত্তি হলে তিনি ব্যাকআপের খবর দেখা ছেড়ে দেবেন।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায় — একজনও `backup.view` না পারলে সব দাবি
     * খালি হাতে সবুজ থাকত।
     */
    public function test_somebody_in_this_company_really_can_see_backups(): void
    {
        $can = User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->whereKey($this->company->id))
            ->get()
            ->filter(fn (User $u) => $u->can('backup.view'));

        $this->assertNotEmpty($can,
            'এই কোম্পানিতে কেউ ব্যাকআপ দেখতে পারেন না — তাহলে উপরের দাবিগুলো কিছুই মাপছে না।');
    }

    private function notices(): int
    {
        return Bell::query()
            ->withoutGlobalScope('company')
            ->where('type', 'backup.failed')
            ->count();
    }
}
