<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ ছাঁকনি ইঞ্জিন — বাঁ পাশের প্যানেল, সব তালিকায় এক (মালিক, ১৪ নভেম্বর ২০২৬: *"সেন্ট্রালি নিয়ন্ত্রিত হওয়াটা ভালো … সবগুলো
 *   স্ক্রিনেই সেমভাবে"*, *"ফুল করে রাখার ব্যবস্থাটা তো রাখার দরকার"*; নমুনা claude.ai/artifact/7UoyiGpYFqJCeuL3dMFveR)।
 *
 * প্রথম তালিকা গ্রাহক ([[CustomerListFilters::set()]]):
 *   - এক অংশে একাধিক টিক = "যেকোনোটা" (দুই এলাকা দুটোরই গ্রাহক), আলাদা অংশ একসাথে খাটে
 *   - ঘরের পাশের সংখ্যা = টিক দিলে যতজন আসে; নিজের অংশ বাদ দিয়ে গোনা
 *   - চিপ মুছলে কেবল সেই মান যায়, বাকি টিক থাকে
 *   - প্যানেল ভাঁজ — মানুষের নিজের হিসাবে থাকে, নিরীক্ষার খাতায় যায় না
 *   - অচেনা মান (অন্য কোম্পানির এলাকা, "1e5") চুপচাপ বাদ, ৫০০ নয়
 */
final class TheFilterPanelIsOneEngineTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ সার্ভার যে ক্লাস আঁকে — `:class`-এর লেখায় "is-folded" শব্দটা সবসময় থাকে, তাই শব্দ নয়, ক্লাসটা ধরা */
    private const FOLDED = '/class="list-filter-layout is-folded"/';

    /** @var array<string, Customer> */
    private array $c = [];

    private Location $north;

    private Location $south;

    private Location $east;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->north = $this->place('ZFN', Location::TERRITORY, null);
        $this->south = $this->place('ZFS', Location::TERRITORY, null);
        $this->east = $this->place('ZFE', Location::TERRITORY, null);
        $northPoint = $this->place('ZFNP', Location::POINT, $this->north);

        // ⓘ নামগুলো অদ্ভুত — পাতার আর কোথাও মিলে যাবে না
        $this->c['n_due'] = $this->customer('Zfx North Due', $northPoint, '700');
        $this->c['n_clean'] = $this->customer('Zfx North Clean', $this->north);
        $this->c['s_due'] = $this->customer('Zfx South Due', $this->south, '300');
        $this->c['e_due'] = $this->customer('Zfx East Due', $this->east, '900');
    }

    public function test_two_ticks_in_one_section_mean_either_and_sections_combine(): void
    {
        $this->sees(['area' => [$this->north->id, $this->south->id]], ['n_due', 'n_clean', 's_due'], ['e_due'],
            'দুই এলাকায় টিক — দুটোরই গ্রাহক আসার কথা');
        $this->sees(['area' => [$this->north->id, $this->south->id], 'quick' => ['due']], ['n_due', 's_due'], ['n_clean', 'e_due'],
            'দুই এলাকা আর "বকেয়া আছে" — দুটো অংশ একসাথে');
        $this->sees(['area' => (string) $this->north->id], ['n_due', 'n_clean'], ['s_due'], 'পুরোনো একক লিংক ?area=…');
    }

    public function test_each_count_is_what_ticking_that_box_brings(): void
    {
        // ⓘ "উত্তর" বাছা থাকলেও "দক্ষিণ"-এর পাশে তার নিজের সংখ্যা — নিজের অংশ গোনায় খাটে না
        $html = $this->page(['area' => [$this->north->id], 'quick' => ['due']]);

        $this->assertSame('1', $this->countBeside($html, 'area', (string) $this->south->id),
            '⛔ "বকেয়া আছে" চালু অবস্থায় দক্ষিণের পাশে ১ হওয়ার কথা (দক্ষিণে একজনেরই বকেয়া)');
        $this->assertSame('1', $this->countBeside($html, 'area', (string) $this->north->id),
            '⛔ উত্তরের পাশে ১ হওয়ার কথা (উত্তরে বকেয়া একজনের)');
        $this->assertSame('1', $this->countBeside($html, 'quick', 'due'),
            '⛔ উত্তর বাছা — "বকেয়া আছে"-র পাশে ১ হওয়ার কথা');

        // ⓘ সংখ্যা মিলিয়ে দেখা — দক্ষিণে টিক দিলে সত্যিই একজন আসে
        $this->sees(['area' => [$this->south->id], 'quick' => ['due']], ['s_due'], ['n_due', 'e_due']);
    }

    public function test_the_counts_follow_the_search_too(): void
    {
        $html = $this->page(['q' => 'Zfx North']);

        $this->assertSame('0', $this->countBeside($html, 'area', (string) $this->south->id),
            '⛔ "Zfx North" খোঁজায় দক্ষিণের পাশে ০ নয় — সংখ্যা তালিকার নিজের কোয়েরি থেকে গোনা হয়নি');
        $this->assertSame('2', $this->countBeside($html, 'area', (string) $this->north->id),
            '⛔ "Zfx North" খোঁজায় উত্তরের পাশে ২ নয়');
    }

    public function test_a_chip_takes_away_only_its_own_tick(): void
    {
        $html = $this->page(['area' => [$this->north->id, $this->south->id], 'due_min' => '100']);

        $this->assertSame(3, substr_count($html, 'data-facet '), '⛔ দুই এলাকা আর বকেয়া — তিনটা চিপ হওয়ার কথা');
        preg_match('/data-facet href="([^"]+)"[^>]*>\s*<span[^>]*>[^<]*ZFN জায়গা/u', $html, $m);
        $this->assertNotEmpty($m, '⛔ উত্তরের চিপে এলাকার নাম নেই');

        $without = html_entity_decode($m[1]);
        parse_str((string) parse_url($without, PHP_URL_QUERY), $query);
        $this->assertSame([(string) $this->south->id], array_map('strval', $query['area'] ?? []),
            '⛔ উত্তরের চিপ মুছলে দক্ষিণের টিকও গেল, বা উত্তর রয়ে গেল');
        $this->assertSame('100', $query['due_min'] ?? null, '⛔ এলাকার চিপ মুছতে বকেয়ার ছাঁকনিও গেল');

        $this->assertMatchesRegularExpression('/data-filter-toggle-count>\s*3\s*</', $html, '⛔ বারের "ছাঁকনি" বোতামে চালু ছাঁকনির সংখ্যা ৩ নয়');
    }

    public function test_the_fold_is_the_persons_own_and_stays_out_of_the_audit_book(): void
    {
        $this->assertDoesNotMatchRegularExpression(self::FOLDED, $this->page([]), '⛔ নতুন মানুষের প্যানেল শুরুতেই ভাঁজ করা');

        $before = DB::table('audit_trails')->count();
        $this->postJson(route('list_filters.fold'), ['folded' => true])->assertOk()->assertJson(['folded' => true]);

        $this->assertTrue($this->owner->fresh()->list_prefs['folded'] ?? false, '⛔ ভাঁজ করা পছন্দ মানুষের হিসাবে জমা হয়নি');
        $this->assertMatchesRegularExpression(self::FOLDED, $this->page([]),
            '⛔ ভাঁজ করে রাখার পরের বার তালিকা খুললে প্যানেল আবার খোলা');
        $this->assertSame($before, DB::table('audit_trails')->count(), '⛔ প্যানেল ভাঁজ করা নিরীক্ষার খাতায় উঠল — থিমের মতো নিজের পছন্দ');

        $this->postJson(route('list_filters.fold'), ['folded' => false])->assertOk();
        $this->assertDoesNotMatchRegularExpression(self::FOLDED, $this->page([]), '⛔ আবার খোলার পরেও ভাঁজ করা');

        $this->postJson(route('list_filters.fold'), ['folded' => 'maybe'])->assertStatus(422);
    }

    public function test_unknown_values_are_dropped_not_obeyed(): void
    {
        $this->sees(['area' => ['abc', '999999'], 'due_min' => '1e5', 'quick' => ['nope']],
            ['n_due', 'n_clean', 's_due', 'e_due'], [], 'অচেনা এলাকা, "1e5" আর অচেনা দৃশ্য — কোনোটাই খাটার কথা নয়');
        $this->assertStringNotContainsString('data-filter-toggle-count', $this->page(['area' => ['abc'], 'due_min' => '1e5']),
            '⛔ অচেনা মান চালু ছাঁকনি হিসেবে গোনা হলো');
    }

    private function countBeside(string $html, string $section, string $value): ?string
    {
        $part = preg_match('/data-filter-section="'.$section.'".*?<\/details>/su', $html, $m) === 1 ? $m[0] : '';
        $found = preg_match('/value="'.preg_quote($value, '/').'"[^>]*>.*?data-count>([^<]*)</su', $part, $c);

        return $found === 1 ? trim($c[1]) : null;
    }

    /** @param  array<string, mixed>  $query */
    private function page(array $query): string
    {
        $response = $this->get(route('customer.index', $query));
        $this->assertSame(200, $response->getStatusCode(), '⛔ ছাঁকনির ঠিকানায় পাতা ভাঙল (৫০০) — অচেনা মান চুপচাপ বাদ পড়ার কথা: '.json_encode($query, JSON_UNESCAPED_UNICODE));

        return (string) $response->getContent();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $in
     * @param  list<string>  $out
     */
    private function sees(array $filters, array $in, array $out, string $why = ''): void
    {
        $html = $this->page($filters);
        $label = ($why !== '' ? $why.' — ' : '').json_encode($filters);

        foreach ($in as $key) {
            $this->assertStringContainsString($this->c[$key]->name_en, $html, "⛔ {$label}: {$key} থাকার কথা, নেই।");
        }

        foreach ($out as $key) {
            $this->assertStringNotContainsString($this->c[$key]->name_en, $html, "⛔ {$label}: {$key} বাদ পড়ার কথা, আছে।");
        }
    }

    private function place(string $code, string $level, ?Location $parent): Location
    {
        return Location::query()->create([
            'company_id' => CompanyContext::id(),
            'parent_id' => $parent?->id,
            'code' => $code,
            'name_en' => $code.' Place',
            'name_bn' => $code.' জায়গা',
            'level' => $level,
            'is_active' => true,
        ]);
    }

    private function customer(string $name, Location $where, ?string $due = null): Customer
    {
        $customer = app(CustomerService::class)->create(['name_en' => $name, 'name_bn' => $name, 'credit_limit' => '0']);
        $customer->forceFill(['location_id' => $where->id])->saveQuietly();

        if ($due !== null) {
            $receivable = Account::query()->where('code', StandardChart::RECEIVABLE)->firstOrFail();
            $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

            app(PostingEngine::class)->post(
                sourceType: 'test:customer-balance',
                sourceId: (int) $customer->id,
                trxDate: now()->toDateString(),
                lines: [
                    ['account_id' => $receivable->id, 'debit' => $due, 'party_type' => Customer::drillSourceType(), 'party_id' => (int) $customer->id],
                    ['account_id' => $capital->id, 'credit' => $due],
                ],
            );
        }

        return $customer->fresh();
    }
}
