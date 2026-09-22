<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * আটটা মডিউলের প্রতিটা রিপোর্ট পাতা সত্যিই খোলে কি না।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ⛔ ঐ রাতে লাইভে **৫০টা রিপোর্ট পাতা একসাথে ৫০০** দিয়েছিল।
 *
 * কারণটা ছোট: `accounts::report.show` পর্দাটা আটটা কন্ট্রোলার দেখায়,
 * আর একটা নতুন চলক (`$centres`) বসানো হয়েছিল কেবল একটাতে। বাকি সাতটা
 * পথে চলকটা যেত না, তাই ঐ সাত মডিউলের প্রতিটা রিপোর্ট ভেঙে পড়ে।
 *
 * ── ⚠️ পরীক্ষাগুলো কেন ধরেনি ────────────────────────────────────────
 * `AccountReportsTest::test_every_report_screen_opens` আছে — কিন্তু সে
 * কেবল **Accounts**-এর পাতাগুলো খোলে, অর্থাৎ ঠিক সেই এক কন্ট্রোলার
 * যেটাতে চলকটা বসানো হয়েছিল। ⓘ যে সাতটা ভাঙল, তাদের কেউ দেখত না।
 *
 * ⭐ তাই এই পাহারাটা তালিকা ধরে নয়, **রুট ধরে** চলে: যে কেউ নতুন একটা
 * `*.report.show` রুট বসালে সে নিজে থেকেই এখানে ঢুকে যায়, আর তার
 * স্লাগগুলোও কন্ট্রোলারের নিজের তালিকা থেকে পড়া হয়।
 */
final class EveryReportScreenOpensInEveryModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        /*
         * ⭐ সুইচগুলো চালু করে মাপা — বন্ধ রেখে নয়।
         *
         * ⓘ [[RefuseSwitchedOffScreens]] বন্ধ সুইচের পর্দায় ৪০৪ দেয়, আর
         * সেটাই ঠিক। ⚠️ কিন্তু বন্ধ রেখে মাপলে এই পাহারাটা ঐ পর্দাগুলো
         * **কোনোদিন খুলেই দেখত না** — অর্থাৎ ডেমোতে যে সুইচ বন্ধ, তার
         * পিছনের প্রতিটা রিপোর্ট চিরকাল অমাপা থেকে যেত।
         */
        app(SettingsService::class)->set('inventory.batch_enabled', true);

        /*
         * ⭐ আর প্রতিটা মডিউলের সুইচও — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কী ঘটেছিল ───────────────────────────────────────────
         * মালিক রেস্তোরাঁ মডিউলটা বন্ধ করলেন, আর
         * [[RefuseSwitchedOffScreens]] ঠিক যা করার তাই করল — ৪০৪। ⓘ
         * কিন্তু এই পাহারাটা **রুটের তালিকা** থেকে পর্দা গোনে, তাই সে
         * ঐ ৪০৪-টাকে ভাঙা পাতা বলে ধরল, আর লাল হয়ে বসে রইল।
         *
         * ⚠️ প্রথমে ভেবেছিলাম বন্ধ মডিউলের রিপোর্টগুলো **বাদ** দেব।
         * ⛔ কিন্তু সেটা উপরের সিদ্ধান্তটারই উল্টো: বাদ দিলে রেস্তোরাঁর
         * প্রতিটা রিপোর্ট **চিরকাল অমাপা** থেকে যেত, আর যেদিন মালিক
         * মডিউলটা আবার চালু করতেন সেদিন ভাঙা পাতাগুলো একসাথে বেরোত।
         *
         * ⭐ তাই উল্টো পথ: সুইচগুলো **চালু করে** মাপা। ⓘ পাহারাটার কাজ
         * কোডটা কাজ করে কি না বলা, কোন কোম্পানি কী কিনেছে তা নয়।
         */
        foreach (app(ModuleRegistry::class)->all() as $module) {
            app(SettingsService::class)->set(
                app(MenuSwitches::class)->forModule($module->code), true
            );
        }
    }

    /**
     * ⛔ প্রতিটা মডিউলের প্রতিটা রিপোর্ট — একটাও ৫০০ নয়।
     */
    public function test_every_report_screen_in_every_module_opens(): void
    {
        $screens = $this->everyReportScreen();

        /*
         * ⚠️ প্রথমে এটা: তালিকাটা খালি হলে নিচের লুপ একবারও চলত না আর
         * পরীক্ষাটা সবুজ থাকত — রুটের নাম বদলে গেলে ঠিক সেটাই হত।
         */
        $this->assertGreaterThan(
            20,
            count($screens),
            'রিপোর্টের পাতা মাত্র '.count($screens).'টা পাওয়া গেল — খোঁজাটাই ভেঙেছে।'
        );

        $broken = [];

        foreach ($screens as [$route, $slug]) {
            $response = $this->get(route($route, ['slug' => $slug]));

            if ($response->getStatusCode() !== 200) {
                $broken[] = $route.':'.$slug.' → '.$response->getStatusCode();
            }
        }

        $this->assertSame([], $broken, implode("\n", array_merge(
            ['এই রিপোর্ট পাতাগুলো খোলে না:', ''],
            $broken,
            ['', '⚠️ পর্দাটা আটটা মডিউল শেয়ার করে। একটা কন্ট্রোলারে নতুন চলক',
                'বসালে বাকিগুলোতেও লাগে — নাহলে ডিফল্টটা ভিউতে বসান।']
        )));
    }

    /**
     * ⛔ আর ভাঙা মানে কেবল ৫০০ নয় — পাতা ২০০ দিয়েও ফাঁকা হতে পারে।
     *
     * ⓘ একটা কম্পোনেন্ট কম্পাইল না হলে সে **লেখা হিসেবে** ছাপা হয় আর
     * পাতাটা তবু ২০০ বলে। ⚠️ ২০ সেপ্টেম্বরেই একটা টেবিল এভাবে উধাও
     * হয়েছিল, আর পরীক্ষা সবুজ ছিল।
     */
    public function test_no_report_screen_prints_a_component_as_text(): void
    {
        $leaking = [];

        foreach ($this->everyReportScreen() as [$route, $slug]) {
            $html = $this->get(route($route, ['slug' => $slug]))->getContent();

            if (str_contains($html, '<x-')) {
                $leaking[] = $route.':'.$slug;
            }
        }

        $this->assertSame([], $leaking, implode("\n", array_merge(
            ['এই পাতাগুলোয় একটা কম্পোনেন্ট লেখা হিসেবে ছাপা হয়েছে:', ''],
            $leaking,
            ['', 'ⓘ প্রায়ই কারণ একটাই: attribute-এর ভিতরে একটা ASCII "'.'"'
                .' — এমনকি মন্তব্যের ভিতরেও।']
        )));
    }

    /**
     * প্রতিটা `*.report.show` রুট আর তার স্লাগগুলো।
     *
     * ⓘ রুটটা খুঁজে তার কন্ট্রোলার ধরে `SLUGS` পড়া হয় — তালিকাটা এখানে
     * হাতে লিখলে নবম মডিউলের দিন সেটা পুরনো হয়ে যেত, আর পাহারাটা
     * ঠিক তখনই অকেজো হত যখন তাকে দরকার।
     *
     * @return list<array{0: string, 1: string}>
     */
    private function everyReportScreen(): array
    {
        $out = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_ends_with($name, '.report.show')) {
                continue;
            }

            $controller = strtok($route->getActionName(), '@');

            if (! class_exists($controller)) {
                continue;
            }

            $slugs = (new ReflectionClass($controller))->getConstants()['SLUGS'] ?? [];

            foreach (array_keys($slugs) as $slug) {
                $out[] = [$name, (string) $slug];
            }
        }

        return $out;
    }
}
