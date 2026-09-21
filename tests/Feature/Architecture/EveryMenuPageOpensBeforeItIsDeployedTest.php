<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * মেনুর প্রতিটা পাতা সত্যিই খোলে — **ডিপ্লয়ের আগে**।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ঐ রাতে **পাঁচটা** জিনিস লাইভে ভাঙা অবস্থায় ধরা পড়েছে, আর পাঁচটার
 * তিনটাই ছিল "পাতাটা ৫০০ দেয়": ৫০টা রিপোর্ট, ভাউচারের দুইটা, আর
 * মজুদের তালিকা। ⓘ তিনটাই ধরেছে **লাইভের স্বাস্থ্য-হাঁটা**, আর সেটা
 * চলে ডিপ্লয়ের **পরে**।
 *
 * ── ⛔ কেন পুরনো পাহারাগুলো ধরেনি ───────────────────────────────────
 * [[NoLeakedBladeTest]] পাতা খোলে, কিন্তু খোঁজে **ফাঁস হওয়া সোর্স**
 * (`{{`, `=> function (`)। ⚠️ একটা ৫০০-তে ফাঁস হওয়া সোর্স থাকে না —
 * থাকে একটা ত্রুটির পাতা। তাই সে চুপ করে পাশ করত।
 *
 * ⓘ আর বাকি পাহারাগুলো **সোর্স পড়ে**, রেন্ডার করে না। ⛔ বিরামচিহ্ন
 * ভুল জায়গায় বসার শ্রেণির ভুল ওরা ধরতেই পারে না — একই পাতায় ঐ ভুল
 * আজ তিনবার হয়েছে (attribute-এর ভিতরে কোট, `<a href>`-এ, আর PHP-র
 * ভিতরে ব্লেড মন্তব্য)।
 *
 * ── ⭐ এই পাহারাটা কী করে ───────────────────────────────────────────
 * মালিকের অধিকারে প্রতিটা মেনু সারি খুলে দেখে সে ২০০ দেয় কি না —
 * যেগুলোর প্যারামিটার লাগে সেগুলোসহ, কারণ মেনু নিজেই `route_params`
 * বলে দেয়। ⓘ অর্থাৎ লাইভের হাঁটাটাই, কেবল **আগে**।
 */
final class EveryMenuPageOpensBeforeItIsDeployedTest extends TestCase
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

    public function test_every_menu_page_opens(): void
    {
        $pages = $this->menuPages();

        /*
         * ⚠️ আগে এটা: তালিকাটা খালি হলে নিচের লুপ একবারও চলত না, আর
         * পাহারাটা চিরকাল সবুজ থাকত — মেনুর গড়ন বদলালে ঠিক সেটাই হত।
         */
        $this->assertGreaterThan(
            60,
            count($pages),
            'মেনুতে মাত্র '.count($pages).'টা পাতা পাওয়া গেল — খোঁজাটাই ভেঙেছে।'
        );

        $broken = [];

        foreach ($pages as [$name, $params]) {
            $status = $this->get(route($name, $params))->getStatusCode();

            /*
             * ⓘ ৪০৩ গ্রহণযোগ্য নয় — মালিকের সব অধিকার আছে। ⚠️ কিন্তু
             * ৩০২ চলে: কিছু পাতা নিজের ভিতরের একটা ট্যাবে পাঠায়।
             */
            if (! in_array($status, [200, 302], true)) {
                $broken[] = $name.' → '.$status;
            }
        }

        sort($broken);

        $this->assertSame([], $broken, implode("\n", array_merge(
            ['⛔ মেনুর এই পাতাগুলো খোলে না:', ''],
            $broken,
            ['',
                '⚠️ সোর্স-পড়া পাহারাগুলো এই শ্রেণির ভুল ধরে না — ওরা ফাইল',
                'পড়ে, পাতা আঁকে না। ⓘ এই পাহারাটাই লাইভের স্বাস্থ্য-হাঁটার',
                'কাজটা করে, কেবল ডিপ্লয়ের **আগে**।']
        )));
    }

    /**
     * ⛔ আর খোলা মানেই ঠিক নয় — পাতা ২০০ দিয়েও ফাঁকা হতে পারে।
     *
     * ⓘ একটা কম্পোনেন্ট কম্পাইল না হলে সে **লেখা হিসেবে** ছাপা হয়, আর
     * পাতাটা তবু ২০০ বলে। ⚠️ ২০ সেপ্টেম্বরে একটা টেবিল এভাবেই উধাও
     * হয়েছিল, আর সব পরীক্ষা সবুজ ছিল।
     */
    public function test_no_menu_page_prints_a_component_as_text(): void
    {
        $leaking = [];

        foreach ($this->menuPages() as [$name, $params]) {
            $response = $this->get(route($name, $params));

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            if (str_contains($response->getContent(), '<x-')) {
                $leaking[] = $name;
            }
        }

        sort($leaking);

        $this->assertSame([], $leaking, implode("\n", array_merge(
            ['⛔ এই পাতাগুলোয় একটা কম্পোনেন্ট লেখা হিসেবে ছাপা হয়েছে:', ''],
            $leaking,
            ['', 'ⓘ প্রায়ই কারণ একটাই: attribute-এর ভিতরে একটা ASCII কোট —',
                'এমনকি মন্তব্যের ভিতরেও।']
        )));
    }

    /**
     * মেনুর প্রতিটা সারি — প্যারামিটারসহ।
     *
     * ⓘ [[NoLeakedBladeTest]] প্যারামিটারওয়ালা রুট বাদ দেয়। ⚠️ কিন্তু
     * মেনু নিজেই `route_params` বলে দেয়, তাই বাদ দেওয়ার দরকার নেই —
     * আর ঐ বাদ পড়াগুলোতেই আমানত ও রিপোর্টের পাতাগুলো পড়ে।
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function menuPages(): array
    {
        $out = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $items) {
                foreach ($items as $item) {
                    $name = $item['route'] ?? null;

                    if ($name === null || ! Route::has($name)) {
                        continue;
                    }

                    /*
                     * ⛔ সুইচ-বন্ধ সারি বাদ — আর সেটাই ঠিক।
                     *
                     * ⓘ [[RefuseSwitchedOffScreens]] বন্ধ সুইচের পর্দায়
                     * ৪০৪ দেয়, আর মেনুও সারিটা দেখায় না। ⚠️ লাইভের
                     * স্বাস্থ্য-হাঁটা মেনু থেকেই তালিকা নেয়, তাই ওগুলো
                     * ওখানেও আসে না — এই পাহারাটা ঠিক সেই হাঁটাটারই
                     * আগাম রূপ, তাই একই তালিকা দেখা উচিত।
                     *
                     * ⭐ সাতটা পাতা এভাবেই ৪০৪ দিচ্ছিল (ব্যাচ, মুদ্রা,
                     * গাড়ি, কাউন্টার, শিফট) — কোনোটাই ভাঙা নয়, সবগুলোই
                     * ডেমোতে বন্ধ।
                     */
                    if (isset($item['setting'])
                        && ! app(SettingsService::class)->get((string) $item['setting'], true)) {
                        continue;
                    }

                    $params = $item['route_params'] ?? [];
                    $uri = Route::getRoutes()->getByName($name)->uri();

                    /* ⓘ প্যারামিটার লাগে অথচ মেনু দেয়নি — ডাকা যাবে না। */
                    if (str_contains($uri, '{') && $params === []) {
                        continue;
                    }

                    $out[$name.json_encode($params)] = [$name, $params];
                }
            }
        }

        return array_values($out);
    }
}
