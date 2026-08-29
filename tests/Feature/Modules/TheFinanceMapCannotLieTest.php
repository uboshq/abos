<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Support\FinancePlan;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ফিন্যান্স মানচিত্র যা "হয়েছে" বলে, সেটা সত্যিই খোলে।
 *
 * ── কেন এই পাহারাটা ──────────────────────────────────────────────────
 * ২৯ আগস্ট ২০২৬-এ মালিক তেত্রিশ বিভাগের পরিকল্পনা দিয়ে বললেন গোটাটা
 * আগে চোখের সামনে থাকুক — *"দেখলে বুঝা যাবে আমি কোন কাজটা করছি আর
 * কোনটা করি নাই"*।
 *
 * একটা মানচিত্রের একমাত্র কাজ সত্যি বলা। "হয়েছে" লেখা একটা লাইন যদি
 * ক্লিকে কোথাও না নিয়ে যায়, তবে ওটা মানচিত্র নয় — ওটা ঠিক সেই জিনিস
 * যেটা আজ সকালে সরাসরি বিক্রয়ের পর্দা থেকে সরানো হয়েছে: একটা বোতাম
 * যা চাপা যায় অথচ কিছু করে না।
 *
 * তার চেয়েও বড় ঝুঁকি উল্টোটা: কেউ একটা পর্দা সরিয়ে দিল, আর মানচিত্র
 * তবু "হয়েছে" বলে গেল। তখন মালিক ভাবতেন কাজটা করা আছে, আর ওটা
 * ধরা পড়ত মাস পরে।
 */
class TheFinanceMapCannotLieTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * প্রতিটা ঘোষিত রুট সত্যিই আছে।
     *
     * ── কেন `urlFor()` নয়, `Route::has()` ────────────────────────────
     * `urlFor()` রুট না থাকলে নাল ফেরত দেয় — অর্থাৎ লাইনটা চুপচাপ
     * "বাকি" হয়ে যায়। ওটাই মানচিত্রকে পর্দায় সৎ রাখে, কিন্তু ভুলটা
     * তখন কেউ জানে না। এখানে সরাসরি জিজ্ঞেস করা হয়, তাই একটা মুছে
     * ফেলা পর্দা নীরবে "বাকি" হয়ে যাওয়ার বদলে লাল হয়।
     */
    public function test_every_route_the_map_names_actually_exists(): void
    {
        $missing = [];

        foreach (FinancePlan::sections() as $section) {
            foreach ($section['items'] as [$label, $route, $note]) {
                if ($route === null) {
                    continue;
                }

                $name = explode(':', $route, 2)[0];

                if (! Route::has($name)) {
                    $missing[] = "§{$section['no']} {$label} → {$route}";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'মানচিত্র এমন পর্দার কথা বলছে যা নেই:',
            ...$missing,
            '',
            'হয় রুটটা ফিরিয়ে আনুন, নয় লাইনটাকে "বাকি" করে দিন —',
            'একটা মানচিত্রের একমাত্র কাজ সত্যি বলা।',
        ]));
    }

    /**
     * আর প্রতিটা "হয়েছে" লাইন সত্যিই খোলে।
     *
     * রুট থাকা আর পাতা খোলা দুই জিনিস: প্যারামিটার ভুল হলে
     * `UrlGenerationException`, অনুমতি না থাকলে ৪০৩। দুইটাই মানচিত্রকে
     * মিথ্যা বানায়, আর দুইটাই কেবল সত্যিই খুলে দেখলে ধরা পড়ে।
     */
    public function test_every_built_line_opens(): void
    {
        $broken = [];

        foreach (FinancePlan::sections() as $section) {
            foreach ($section['items'] as [$label, $route, $note]) {
                $url = FinancePlan::urlFor($route);

                if ($url === null) {
                    continue;
                }

                $status = $this->get($url)->getStatusCode();

                /* ৩০২ ঠিক আছে — কিছু পর্দা ছাঁকনি নিয়ে নিজের দিকে পাঠায় */
                if (! in_array($status, [200, 302], true)) {
                    $broken[] = "§{$section['no']} {$label} → {$url} = {$status}";
                }
            }
        }

        $this->assertSame([], $broken, implode("\n", [
            'মানচিত্রে "হয়েছে" লেখা, অথচ খোলে না:',
            ...$broken,
        ]));
    }

    /**
     * অর্থ একটা সত্যিকারের মডিউল, আর হিসাবের পাশে দাঁড়ায়।
     *
     * ── কেন এটা পরীক্ষার যোগ্য ───────────────────────────────────────
     * মডিউল রেজিস্ট্রি ফোল্ডার আর `module.php` দেখে চলে। একটা ভুল
     * নাম বা একটা অনুপস্থিত চাবি থাকলে মডিউলটা **চুপচাপ বাদ পড়ে** —
     * কোনো ভুল দেখা যায় না, কেবল মেনুতে অর্থ থাকে না।
     */
    public function test_finance_stands_beside_accounts_as_its_own_module(): void
    {
        $codes = collect(app(ModuleRegistry::class)->all())->map(fn ($m) => $m->code);

        $this->assertTrue($codes->contains('finance'), 'অর্থ মডিউলটাই তালিকায় নেই।');
        $this->assertTrue($codes->contains('accounts'), 'হিসাব মডিউলটা হারিয়ে গেছে।');
    }

    /**
     * পুরনো ঠিকানা ভাঙে না।
     *
     * মূলধন কয়েক ঘণ্টা `accounts/capital`-এ ছিল। কেউ বুকমার্ক করে
     * থাকলে সে যেন নতুন জায়গায় পৌঁছায় — মডিউল ভাগ করার সময়কার
     * নিয়ম।
     */
    public function test_the_old_address_still_leads_somewhere(): void
    {
        $this->get('/accounts/capital')->assertRedirect(route('finance.capital.index'));
    }
}
