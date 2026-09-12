<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Services\YearEndService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নম্বর প্রতি বছর ১ থেকে শুরু হবে কি না — কে ঠিক করে।
 *
 * ── ⚠️ এই ফাইলটা একটা ভুল থেকে জন্মেছে, ৫ সেপ্টেম্বর ২০২৬ ────────────
 * মালিক জিজ্ঞেস করলেন নম্বরের নিয়ন্ত্রণ কন্ট্রোল প্যানেলে আছে কি না।
 * আমি ধরে নিলাম নেই, আর কোম্পানি-ব্যাপী একটা সুইচ বসিয়ে ফেললাম —
 * সাথে ইঞ্জিনে একটা "বছরহীন সারি" বানানোর শাখা।
 *
 * ⛔ **জিনিসটা আগে থেকেই ছিল।** `number_series.reset_yearly` — প্রতিটা
 * কাগজের নিজের ঘর, নম্বর সিরিজের পর্দা থেকে বদলানো যায়, আর
 * [[YearEndService::carryNextNumbers()]] বছর বদলের সময় সেটা পড়ে।
 *
 * ⭐ আর কাগজ ধরে হওয়াটাই **বেশি সঠিক** — মালিকের কথা: *"যে যা পছন্দ
 * করে তা দিবে।"* চালান রিসেট হোক, ভাউচার চলতে থাকুক; একটা কোম্পানি-
 * ব্যাপী সুইচ ঐ পার্থক্যটা করতেই পারত না।
 *
 * ⚠️ আজ আমরা চারবার পেয়েছি *"মন্তব্যে নিয়ম লেখা, কোডে নেই"*। এটা তার
 * উল্টো: **কোডে ছিল, আমি খুঁজিনি।** তাই সুইচটা তুলে নেওয়া হয়েছে, আর
 * এই ফাইলটা এখন যা সত্যিই আছে তারই পাহারা — যাতে পরের জন আবার একই
 * দ্বিতীয় নিয়ন্ত্রণ না বানান।
 */
class TheNumbersRestartedEveryYearAndNobodyCouldSayOtherwiseTest extends TestCase
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

    /**
     * ⭐ সিদ্ধান্তটা কাগজ ধরে — কোম্পানি ধরে নয়।
     *
     * ⓘ প্রতিটা সিরিজের নিজের `reset_yearly` ঘর আছে, তাই এক কোম্পানিতেই
     * চালান রিসেট হয়ে ভাউচার চলতে পারে।
     */
    public function test_each_document_type_decides_for_itself(): void
    {
        $series = NumberSeries::query()->orderBy('id')->get();

        $this->assertGreaterThan(5, $series->count(),
            'ডেমোতে সিরিজই বসেনি — তাহলে নিচের দাবিটা কিছুই মাপত না।');

        foreach ($series as $one) {
            $this->assertContains($one->reset_yearly, [true, false],
                "সিরিজ {$one->doc_type}-এ reset_yearly-র কোনো মান নেই।");
        }

        /*
         * ⛔ আর কোম্পানি-ব্যাপী কোনো দ্বিতীয় সুইচ নেই — ইচ্ছাকৃতভাবে।
         *
         * ⚠️ থাকলে একই প্রশ্নের দুইটা উত্তর থাকত, আর একদিন সেটিংস
         * বলত "রিসেট হবে" অথচ সারিটা বলত "হবে না"।
         */
        /*
         * ⓘ `SettingsService::get()` অচেনা চাবিতে **ব্যতিক্রম ছোড়ে**,
         * `null` দেয় না — তাই ঘোষণার তালিকাটাই দেখা হয়। ⚠️ প্রথমে
         * `get()` দিয়ে মাপতে গিয়ে পরীক্ষাটা নিজেই ভেঙেছিল, আর সেটা
         * ভালোই: অচেনা চাবি নীরবে `null` দিলে বানান-ভুল ধরা পড়ত না।
         */
        $this->assertArrayNotHasKey(
            'master_data.series_reset_yearly',
            app(SettingsService::class)->definitions(),
            'নম্বরের রিসেট নিয়ে একটা দ্বিতীয় নিয়ন্ত্রণ ফিরে এসেছে — সিদ্ধান্তটা সিরিজের সারিতেই থাকার কথা।',
        );
    }

    /**
     * ⭐ `reset_yearly` সত্যি হলে নতুন বছরে গুনতি শুরু থেকে।
     *
     * ⓘ কলামটা এতদিন সংরক্ষিত হত অথচ কেউ পড়ত না, কারণ বছর বদলানোর
     * ব্যবস্থাই ছিল না। এখন এটাই তার একমাত্র অর্থ — আর সেটা এখানে
     * পাহারা দেওয়া হয়, নাহলে কেউ একদিন লাইনটা তুলে দিলে ধরা পড়ত না।
     */
    public function test_when_it_resets_the_new_year_starts_from_the_start_number(): void
    {
        $this->assertStringContainsString(
            '$before->reset_yearly ? $before->start_number : $before->next_number',
            (string) file_get_contents(app_path('Modules/Accounts/Services/YearEndService.php')),
            'বছর বদলের সময় reset_yearly আর পড়া হচ্ছে না — কলামটা তখন অর্থহীন।',
        );

        /*
         * ⓘ আগে এখানে `close() || roll()` লেখা ছিল। `roll()` বলে কোনো
         * পদ্ধতি কোনোদিন ছিল না, তাই শাখাটা কখনো চলত না — আর `||`-এর
         * বাঁ দিক সত্য বলে দাবিটা তবু ঠিক উত্তর দিত। মৃত বিকল্পটা রাখলে
         * পরের জন ভাবতেন দুইটা নামই বৈধ, আর একদিন ভুল নামটা খুঁজতেন।
         */
        $this->assertTrue(
            method_exists(YearEndService::class, 'close'),
            'বছর বদলানোর সেবাটাই নেই — তাহলে reset_yearly কোথায় পড়া হবে?',
        );
    }
}
