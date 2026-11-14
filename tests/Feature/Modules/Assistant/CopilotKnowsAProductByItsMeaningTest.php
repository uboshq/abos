<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Assistant;

use App\Core\Engines\Assistant\MeaningIndex;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Assistant\ProductInQuestion;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ⭐ ১৩ নভেম্বর ২০২৬ (মালিক: "ম্যাকমিনিতে আরও কিছু নতুন এআই সংযোগ করা হয়েছে"): কোপাইলট পণ্য চিনত কেবল অক্ষর মিলিয়ে —
 *   "cosmos biskut" বা "বিস্কিট" প্রশ্নে "কসমস বিস্কুট ৪০ গ্রাম" পেত না। এখন অক্ষরে না মিললে মানে ধরে (bge-m3, [[MeaningIndex]])।
 *
 * দাবি: রাতের কাজ সব সক্রিয় পণ্যের ছাপ রাখে আর পরের রাতে কেবল বদলানোটার; মানে ধরে ঠিক পণ্য আসে; দুইটা সমান কাছে হলে বা গেটওয়ে
 *   বন্ধ থাকলে আন্দাজ নয় — null; অক্ষরে মিললে গেটওয়েকে ডাকাই হয় না; গেটওয়েতে যায় কেবল নাম, মোবাইলের মতো লম্বা অঙ্ক ঢাকা।
 */
final class CopilotKnowsAProductByItsMeaningTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> গেটওয়েতে যা গেল */
    private array $sent = [];

    /** ⓘ গেটওয়ে বন্ধ — দ্বিতীয় Http::fake আগেরটাকে বদলায় না, তাই একটা পতাকা */
    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $settings = app(SettingsService::class);
        $settings->set('assistant.model_url', 'https://ai.office.test');
        $settings->set('assistant.model_kind', 'chat');

        // ⓘ তিন দিকের নকল ছাপ — বিস্কুট, চাল, বাকি সব; প্রশ্নের শব্দ যেদিকে বলা
        Http::fake(['ai.office.test/v1/embeddings' => function (Request $request) {
            if ($this->down) {
                return Http::response('down', 503);
            }

            $inputs = (array) $request['input'];
            $this->sent = [...$this->sent, ...$inputs];

            return Http::response(['data' => array_map(fn (string $t): array => ['embedding' => self::vector($t)], $inputs)]);
        }]);
    }

    private static function vector(string $text): array
    {
        return match (true) {
            str_contains($text, 'Cosmos') => [1.0, 0.0, 0.0],
            str_contains($text, 'Rice') => [0.0, 1.0, 0.0],
            $text === 'cosmos biskut' => [0.9, 0.1, 0.0],
            $text === 'বিস্কুট না চাল' => [1.0, 1.0, 0.0],
            // ⓘ বিস্কুট একাই এগিয়ে (ব্যবধান ০.৩), কিন্তু মিল ০.৩ — সীমার নিচে; ব্যবধানের নিয়ম একে আটকায় না, কেবল সর্বনিম্ন মিল
            $text === 'ধাতুর পাইপ' => [0.3, 0.0, -0.954],
            default => [0.0, 0.0, 1.0],
        };
    }

    public function test_the_night_job_keeps_one_print_per_active_product_and_next_night_only_the_renamed_one(): void
    {
        $this->artisan('abos:copilot-meaning')->assertSuccessful();
        $active = Product::query()->where('is_active', true)->count();
        $this->assertSame($active, DB::table('assistant_vectors')->where('kind', 'product')->count(), '⛔ সব সক্রিয় পণ্যের ছাপ নেই।');

        $this->sent = [];
        $cosmos = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $cosmos->forceFill(['name_en' => 'Cosmos Biscuit 45gm'])->save();
        $this->artisan('abos:copilot-meaning')->assertSuccessful();

        $this->assertSame(['কসমস বিস্কুট ৪০ গ্রাম · Cosmos Biscuit 45gm'], $this->sent, '⛔ পরের রাতে সব নাম আবার গেল — কেবল বদলানোটা যাওয়ার কথা।');
    }

    public function test_a_misspelt_name_finds_the_product_by_meaning(): void
    {
        $this->artisan('abos:copilot-meaning')->assertSuccessful();

        $found = ProductInQuestion::find(['product' => 'cosmos biskut', 'q' => 'cosmos biskut koto ache']);

        $this->assertSame('Cosmos Biscuit 40gm', $found?->name_en, '⛔ অক্ষর না মিললেই পণ্য হারায় — মানে ধরে খোঁজা হয়নি।');
    }

    public function test_two_equally_close_products_or_nothing_close_give_no_guess(): void
    {
        $this->artisan('abos:copilot-meaning')->assertSuccessful();

        $this->assertNull(ProductInQuestion::find(['product' => 'বিস্কুট না চাল']), '⛔ দুইটা সমান কাছে, তবু একটা বেছে নিল — আন্দাজ।');
        $this->assertNull(ProductInQuestion::find(['product' => 'ধাতুর পাইপ']), '⛔ কোনোটাই কাছে নয়, তবু একটা পণ্য দিল।');
        $this->assertNull(app(MeaningIndex::class)->nearest('product', 'x'), 'এক অক্ষর — খোঁজা নয়।');
    }

    public function test_a_name_matched_by_letters_never_calls_the_gateway_and_a_closed_gateway_breaks_nothing(): void
    {
        $this->artisan('abos:copilot-meaning')->assertSuccessful();
        $this->sent = [];

        $this->assertSame('Cosmos Biscuit 40gm', ProductInQuestion::find(['product' => 'কসমস বিস্কুট'])?->name_en,
            '⛔ অক্ষরে মেলা পণ্যটা হারাল — মানে-খোঁজা শব্দের মিলকে ঢেকে দিল।');
        $this->assertSame([], $this->sent, '⛔ অক্ষরে মিলেছে, তবু গেটওয়েকে ডাকল — প্রতিটা প্রশ্নে অযথা দেরি।');

        $this->down = true;
        app()->forgetInstance(MeaningIndex::class);
        $this->assertNull(ProductInQuestion::find(['product' => 'cosmos biskut']), '⛔ গেটওয়ে বন্ধ, তবু উত্তর বানাল।');
    }

    public function test_only_the_name_goes_out_and_long_numbers_are_masked(): void
    {
        $this->artisan('abos:copilot-meaning')->assertSuccessful();
        $this->sent = [];

        ProductInQuestion::find(['product' => 'biskut 01712345678']);

        $this->assertSame(['biskut ••••'], $this->sent, '⛔ মোবাইল নম্বর গেটওয়েতে গেল।');
    }
}
