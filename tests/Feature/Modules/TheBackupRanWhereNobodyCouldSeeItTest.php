<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Backup\Models\BackupDestination;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ব্যাকআপ চলত এমন জায়গায় যেখানে কেউ দেখতে পেত না।
 *
 * ── কী ছিল, আর কী ছিল না ─────────────────────────────────────────────
 * ইঞ্জিনটা অনেক আগেই সম্পূর্ণ: ডাম্প নেওয়া, ফিরিয়ে এনে যাচাই করা,
 * দ্বিতীয় গন্তব্যে কপি, পুরনো মোছা — সবই। একটা কমান্ড রোজ চলেও।
 *
 * **কেবল দেখার কোনো উপায় ছিল না।** মেনুর সারিটা `planned` হিসেবে
 * ঘোষিত ছিল, অর্থাৎ লুকানো: কেউ ক্লিক করে ভাঙত না, কিন্তু প্রতিশ্রুতিটা
 * রয়ে গিয়েছিল আর জিনিসটা ছিল না।
 *
 * ── কেন এটা ছোট ব্যাপার নয় ──────────────────────────────────────────
 * ২৫ আগস্ট ২০২৬-এ ব্যাকআপ ঠিক আছে কি না জানতে সার্ভারে ssh করে ফোল্ডার
 * দেখতে হয়েছে, আর ফাইলগুলো সত্যিই কাজের কি না জানতে হাতে একটা আলাদা
 * ডাটাবেজে ফিরিয়ে আনতে হয়েছে।
 *
 * মালিকের ওই দুইটার কোনোটাই করার কথা নয় — আর যিনি করতে পারেন না,
 * তিনি ধরে নেন সব ঠিক আছে। ব্যাকআপের ব্যর্থতা ঠিক এভাবেই নীরব থাকে।
 */
class TheBackupRanWhereNobodyCouldSeeItTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** পর্দাটা খোলে, আর যা জানতে মানুষ আসে তা বলে। */
    public function test_the_screen_answers_the_only_question_people_come_with(): void
    {
        config(['abos.backup.path' => $dir = storage_path('framework/testing/backup-screen')]);

        /*
         * ⚠️ ৫ সেপ্টেম্বর ২০২৬ — এখানে আগে `abos.backup.mirror => null`
         * বসিয়ে পর্দায় `core.backup.no_mirror` খোঁজা হত।
         *
         * ⛔ দ্বিতীয় কপি আর `.env`-এর একটা পথ নয়; সেটা এখন গন্তব্যের
         * সারি, কোম্পানি নিজে বসায় — পেনড্রাইভ, অন্য ড্রাইভ বা অন্য
         * কম্পিউটার। ⓘ অর্থাৎ পুরনো চাবিটা আর কোনো পর্দাতেই ছাপা হয়
         * না; পরীক্ষাটা বাসি ছিল, ফিচারটা নয়।
         *
         * ⭐ প্রশ্ন তিনটে বদলায়নি, তাই সেই তিনটেই মাপা হয় — আজকের
         * শব্দে। গন্তব্য মুছে অবস্থাটা নিশ্চিত করা হয়, নাহলে ডেমো
         * ডেটা বদলালে পরীক্ষাটা দুলত।
         */
        BackupDestination::query()->delete();

        @mkdir($dir, 0775, true);

        $name = 'abos-'.now()->format('Y-m-d-His').'.sql.gz';
        file_put_contents($dir.DIRECTORY_SEPARATOR.$name, 'a file, not a real dump');

        $this->actingAs($this->owner)
            ->get(route('backup.index'))
            ->assertOk()
            ->assertSee($name)                              // শেষটা কোনটা
            ->assertSee(__('backup::message.no_destination'))   // দ্বিতীয় কপি আছে কি না
            ->assertSee('abos:restore');                    // দরকারে কী করতে হবে

        @unlink($dir.DIRECTORY_SEPARATOR.$name);
    }

    /**
     * ফিরিয়ে আনার কোনো রুট নেই — আর সেটা ইচ্ছাকৃত।
     *
     * ── কেন এটা পাহারা দেওয়ার মতো সিদ্ধান্ত ──────────────────────────
     * `BackupService::restore()` তৈরি আছে, আর পর্দায় একটা বোতাম বসানো
     * পাঁচ মিনিটের কাজ। বসানো হয়নি, কারণ ফিরিয়ে আনা মানে **আজকের সব
     * কাজ মুছে ফেলা** — একটা ভুল ক্লিকের দাম গোটা দিনের বই।
     *
     * এটা এমন সিদ্ধান্ত যা পরে কেউ "সুবিধার জন্য" উল্টে দিতে পারেন।
     * তাই কারণটা কেবল মন্তব্যে নয়, একটা পরীক্ষাতেও।
     */
    public function test_there_is_no_way_to_restore_from_a_screen(): void
    {
        $restoring = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_contains($name, 'backup') && str_contains($name, 'restore')) {
                $restoring[] = $name;
            }
        }

        $this->assertSame([], $restoring, implode("\n", [
            'ব্যাকআপ ফিরিয়ে আনার একটা রুট বসেছে:',
            ...$restoring,
            '',
            'ফিরিয়ে আনা আজকের সব কাজ মুছে দেয়। ওটা কমান্ড লাইনের কাজ,',
            'যেখানে ভুল ক্লিক হয় না। পর্দায় থাকে কেবল নির্দেশটা।',
        ]));
    }

    /** অনুমতি ছাড়া পর্দাটা খোলে না — ব্যাকআপে গোটা ব্যবসার তথ্য থাকে। */
    public function test_it_is_shut_to_anyone_without_the_permission(): void
    {
        $plain = User::query()->where('email', '!=', 'owner@abos.test')->firstOrFail();

        $this->actingAs($plain)
            ->get(route('backup.index'))
            ->assertForbidden();

        $this->actingAs($plain)
            ->post(route('backup.store'))
            ->assertForbidden();
    }

    /**
     * মেনুর সারিটা আর প্রতিশ্রুতি নয়।
     *
     * ── কেন এটা আলাদা করে মাপা ────────────────────────────────────────
     * `planned => true` মানে "রুট এখনো নেই, সেটাই স্বাভাবিক" — আর
     * `ModuleMenuTest` তখন কিছু বলে না। ফলে একটা প্রতিশ্রুতি চিরকাল
     * বসে থাকতে পারত।
     *
     * পতাকাটা তোলা হয়েছে, তাই সারিটা এখন সত্যিই একটা পর্দায় যায়।
     */
    /*
     * ⚠️ ৫ সেপ্টেম্বর ২০২৬ — ব্যাকআপ এখন নিজের মডিউল, SystemAdmin-এর
     * সেটিংসের একটা সারি নয়। ⛔ এই পরীক্ষাটা পুরনো জায়গায় খুঁজত, আর
     * "সারিটাই নেই" বলে থামত — অথচ সারিটা আছে, শুধু নিজের ঘরে।
     *
     * ⓘ একই আকৃতির ভুল আজ তৃতীয়বার (রান্নাঘরের ক্লাস · রান্নাঘরের রুট ·
     * এখানে): মডিউল সরে গেছে, টেস্ট পুরনো নাম ধরে বসে আছে। ⭐ পরীক্ষাটা
     * ভুল ছিল, ফিচারটা নয়।
     */
    public function test_the_menu_row_is_no_longer_a_promise(): void
    {
        $module = require app_path('Modules/Backup/module.php');

        $row = collect($module['menu']['transactions'])
            ->firstWhere('label', 'backup::menu.backups');

        $this->assertNotNull($row, 'ব্যাকআপের মেনু সারিটাই নেই।');
        $this->assertFalse($row['planned'] ?? false, 'সারিটা এখনো planned হিসেবে ঘোষিত।');
        $this->assertTrue(Route::has($row['route']), "রুট {$row['route']} নেই।");
    }
}
