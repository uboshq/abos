<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * কোনো পর্দা যেন নিজের কোড ছাপিয়ে না দেয়।
 *
 * ── কেন এই টেস্টটা আছে ──────────────────────────────────────────────
 * Blade-এ একটা অ্যাট্রিবিউটের ভেতরে বহু-লাইনের অ্যারে লেখা যায়:
 *
 *     <x-ui.table :columns="[ ['key' => 'code', ...] ]" />
 *
 * ওই অ্যারের ভেতরে মন্তব্যে একটা উদ্ধৃতিচিহ্ন পড়লেই HTML পার্সার ঠিক
 * ওখানেই অ্যাট্রিবিউটটা শেষ ধরে নেয়, আর বাকি পুরো অ্যারেটা পাতায় কাঁচা
 * লেখা হিসেবে ছাপা হয়ে যায়। পাতাটা ২০০ ফেরত দেয়, কোনো এক্সেপশন হয় না,
 * টেস্টও সবুজ থাকে — শুধু ব্যবহারকারী টেবিলের জায়গায় PHP কোড দেখেন।
 *
 * এই ভুলটা এই প্রকল্পে তিনবার হয়েছে: সরবরাহকারীর তালিকায় একবার, আর
 * মজুদের তালিকায় দুইবার। তিনবারই ধরা পড়েছে ব্রাউজারে চোখে দেখে, কারণ
 * কোড রিভিউতে বা সবুজ টেস্টে এটা দেখা যায় না।
 *
 * তাই তালিকাটা হাতে লেখা নয় — মডিউলের ঘোষণা থেকেই আসে। নতুন মডিউলের
 * নতুন পর্দা যেদিন যোগ হবে, সেদিন থেকেই এই পাহারা তার উপরেও থাকবে।
 */
class NoLeakedBladeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ছাপা হয়ে গেলে বোঝা যায় এমন চিহ্ন।
     *
     * প্রতিটাই PHP অ্যারে বা ক্লোজারের অংশ — রেন্ডার হওয়া HTML-এ এগুলোর
     * একটাও থাকার কোনো বৈধ কারণ নেই।
     *
     * @var list<string>
     */
    private const LEAKS = [
        "'key' =>",
        "'render' =>",
        "'label' =>",
        '=> fn (',
        '=> function (',
        '@endforeach',
        '@endif',
    ];

    public function test_no_screen_prints_its_own_source(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $checked = [];
        $offenders = [];

        foreach ($this->menuRoutes() as $name) {
            $url = route($name);

            $body = $this->get($url)->getContent();
            $checked[] = $name;

            foreach (self::LEAKS as $needle) {
                if (str_contains((string) $body, $needle)) {
                    $offenders[] = "{$name} ({$url}) — কাঁচা লেখা: {$needle}";
                    break;
                }
            }
        }

        $this->assertNotSame([], $checked, 'কোনো মেনু রুট পরীক্ষা করা হয়নি — পাহারাটাই অচল।');

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলো নিজেদের কোড ছাপিয়ে দিচ্ছে:',
            ...$offenders,
            '',
            'সম্ভবত :columns="[...]" অ্যাট্রিবিউটের ভেতরে মন্তব্যে একটা উদ্ধৃতিচিহ্ন আছে।',
            'ব্যাখ্যাটা অ্যাট্রিবিউটের বাইরে সরান।',
        ]));
    }

    /**
     * সব মডিউলের মেনুতে ঘোষিত রুট, যেগুলো প্যারামিটার ছাড়াই খোলা যায়।
     *
     * @return list<string>
     */
    private function menuRoutes(): array
    {
        $names = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $items) {
                foreach ($items as $item) {
                    $name = $item['route'];

                    if (! Route::has($name)) {
                        continue;
                    }

                    // প্যারামিটার লাগে এমন রুট বাদ — ওগুলো আলাদা করে
                    // ডাকতে হলে এখানে প্রতিটা মডিউলের নাম লিখতে হত
                    if (str_contains(Route::getRoutes()->getByName($name)->uri(), '{')) {
                        continue;
                    }

                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }
}
