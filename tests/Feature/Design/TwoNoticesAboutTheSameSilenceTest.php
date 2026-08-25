<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * একই নীরবতা নিয়ে দুইটা বার্তা।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ব্যাকআপ বাসি হলে ফুটারে **দুইটা লাল বার্তা পাশাপাশি** বসত:
 *
 *   "দুই দিনের বেশি ব্যাকআপ নেই — ডিস্ক গেলে কিছুই ফেরানো যাবে না।"
 *   "ব্যাকআপ আর বই একই ডিস্কে — দ্বিতীয় গন্তব্য বসান।"
 *
 * মালিক ২৫ আগস্ট ২০২৬-এ পুরো ERP ঘুরে দেখে ওটাকে **ডুপ্লিকেট রেন্ডার**
 * বলে রিপোর্ট করেছেন। কোডের দিক থেকে ওগুলো দুইটা আলাদা বার্তা, কিন্তু
 * পড়াটা ন্যায্য: দুইটাই লাল, দুইটাই ব্যাকআপ নিয়ে, একসাথে।
 *
 * ── আর কেবল দেখতে খারাপ নয়, যুক্তিতেও ভুল ────────────────────────────
 * প্রথমটা সত্যি হলে দ্বিতীয়টার কোনো মানেই থাকে না: **যে ব্যাকআপ নেই,
 * তার দ্বিতীয় কপি হবে কীসের?** মিররের প্রশ্নটা ওঠে কেবল ব্যাকআপ থাকলে।
 *
 * দুইটা লাল বার্তা পাশাপাশি রাখলে মানুষ দুইটাকে একটা ভেবে একটাই পড়েন —
 * আর তখন কোনটা আসল সমস্যা তা আর বোঝা যায় না।
 */
class TwoNoticesAboutTheSameSilenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        Cache::flush();
    }

    /**
     * ব্যাকআপই না থাকলে মিররের কথা তোলা হয় না।
     *
     * ── কেন ব্যাকআপের পথটা একটা খালি ফোল্ডারে সরানো হয় ────────────────
     * "কোনো ব্যাকআপ নেই" অবস্থাটা সাজানোর সবচেয়ে সৎ উপায় এটাই —
     * সত্যিই কোনো ডাম্প নেই। সময় এগিয়ে দিলে বা ফাইল ছুঁয়ে দিলে
     * পরীক্ষাটা ঘড়ির উপর নির্ভর করত, আর ঘড়ি নিয়ে খেলা এই প্রকল্পে
     * ইতিমধ্যেই একবার ভুল উত্তর দিয়েছে।
     */
    public function test_when_there_is_no_backup_the_mirror_is_not_mentioned(): void
    {
        config([
            'abos.backup.path' => $empty = storage_path('framework/testing/no-backups-here'),
            'abos.backup.mirror' => null,
        ]);

        @mkdir($empty, 0775, true);

        $texts = array_column(app(StatusNotices::class)->all(), 'text');

        $stale = __('core.notice.backup_stale');
        $noMirror = __('core.notice.backup_no_mirror');

        $this->assertContains($stale, $texts, 'ব্যাকআপ নেই, অথচ সেটাই বলা হচ্ছে না।');

        $this->assertNotContains($noMirror, $texts,
            'ব্যাকআপই নেই, তবু মিরর নিয়ে দ্বিতীয় একটা লাল বার্তা বসেছে।');
    }

    /**
     * ব্যাকআপ থাকলে মিররের কথাটা ফিরে আসে।
     *
     * উপরেরটা একা থাকলে মিররের সতর্কবার্তাটা চিরতরে চুপ করিয়ে দিয়েও
     * পাশ করানো যেত — আর তখন "বই আর ব্যাকআপ একই ডিস্কে" কথাটা কেউ
     * কোনোদিন জানত না।
     */
    public function test_but_with_a_backup_in_hand_the_mirror_still_speaks(): void
    {
        config([
            'abos.backup.path' => $dir = storage_path('framework/testing/with-a-backup'),
            'abos.backup.mirror' => null,
        ]);

        @mkdir($dir, 0775, true);

        $dump = $dir.DIRECTORY_SEPARATOR.'abos-'.now()->format('Y-m-d-His').'.sql.gz';
        file_put_contents($dump, 'not a real dump, but a real file');

        $texts = array_column(app(StatusNotices::class)->all(), 'text');

        @unlink($dump);

        $this->assertNotContains(__('core.notice.backup_stale'), $texts,
            'টাটকা ডাম্প আছে, তবু "ব্যাকআপ নেই" বলা হচ্ছে।');

        $this->assertContains(__('core.notice.backup_no_mirror'), $texts,
            'দ্বিতীয় গন্তব্য বসানো নেই, অথচ কেউ কিছু বলছে না।');
    }

    /**
     * রূপের ফাইলের বোতাম দুইটা পাশের মেনুর নামের সাথে মেলে না।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * বোতামটার নাম ছিল *"Bring in from a file"*, আর ঠিক ওই পাতার
     * সাইডবারেই আছে *"Bring in from the old books"* — পুরনো খাতা থেকে
     * ডাটা আমদানির পর্দা। দুইটা সম্পূর্ণ আলাদা কাজ, প্রায় এক নাম,
     * এক পর্দায়।
     *
     * মালিক ওটাকে "একটা বাটন দুইবার রেন্ডার হচ্ছে" বলে রিপোর্ট
     * করেছেন — অর্থাৎ নামটা এতটাই কাছাকাছি ছিল যে পড়ে দুইটাকে এক
     * জিনিস মনে হয়েছে। সেটাই নামের ব্যর্থতা।
     *
     * এখন দুইটাতেই **রূপ** শব্দটা আছে, তাই কোনটা কী তা নাম দেখেই বোঝা যায়।
     */
    public function test_the_look_file_buttons_do_not_read_like_the_data_import_screen(): void
    {
        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);

            $dataImport = __('core.import.title');

            foreach (['core.look.import', 'core.look.export'] as $key) {
                $this->assertNotSame($dataImport, __($key),
                    "{$locale}: {$key} আর পুরনো খাতার আমদানির নাম এক হয়ে গেছে।");
            }

            $this->assertStringContainsString(
                $locale === 'bn' ? 'রূপ' : 'look',
                __('core.look.import'),
                "{$locale}: বোতামটা বলছে না সে কী আনে — পাশের মেনুর নামের সাথে গুলিয়ে যাবে।",
            );
        }
    }
}
