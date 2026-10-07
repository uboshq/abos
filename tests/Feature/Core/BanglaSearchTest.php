<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Normalizer;
use Tests\TestCase;

/**
 * একই বাংলা শব্দ, দুই কীবোর্ড, এক ফল।
 *
 * বাংলায় ড় ঢ় য় দুইভাবে লেখা যায় — একক অক্ষর হিসেবে (U+09DC), বা ড
 * আর নুক্তা আলাদা করে (U+09A1 + U+09BC)। পর্দায় হুবহু এক, বাইট আলাদা।
 * Avro, Bijoy, Android ও iOS — সবাই একই রূপ পাঠায় না।
 *
 * ধরা পড়েছে হিসাবের ছকে: "ভাড়া" খাতটা তালিকায় চোখের সামনে, তবু
 * খুঁজলে শূন্য ফল। শব্দগুলো এত সাধারণ (বাড়ি, ভাড়া, পড়া, নয়, হয়) যে
 * এটা না ধরলে অ্যাপের প্রতিটা খোঁজা আধা-কাজ করত।
 */
class BanglaSearchTest extends TestCase
{
    use RefreshDatabase;

    /** ভা + ড়(একক অক্ষর) + া */
    private const PRECOMPOSED = "\u{09AD}\u{09BE}\u{09DC}\u{09BE}";

    /** ভা + ড + নুক্তা + া */
    private const DECOMPOSED = "\u{09AD}\u{09BE}\u{09A1}\u{09BC}\u{09BE}";

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    public function test_the_two_spellings_really_are_different_bytes(): void
    {
        // এটা প্রমাণ যে সমস্যাটা কাল্পনিক নয় — বাকি পরীক্ষাগুলোর ভিত্তি
        $this->assertNotSame(self::PRECOMPOSED, self::DECOMPOSED);
        $this->assertSame(
            Normalizer::normalize(self::PRECOMPOSED, Normalizer::FORM_C),
            Normalizer::normalize(self::DECOMPOSED, Normalizer::FORM_C),
        );
    }

    public function test_a_name_saved_in_one_spelling_is_found_by_the_other(): void
    {
        // একক-অক্ষর রূপে সেভ করা — যেভাবে কিছু কীবোর্ড পাঠায়
        $this->actingAs($this->user)->post(route('customer.store'), [
            'name_en' => 'Bhara Store',
            'name_bn' => self::PRECOMPOSED.' স্টোর',
            'opening_balance' => '0',
        ])->assertRedirect();

        // কোড ধরে যাচাই, নাম ধরে নয়: ব্যবহারকারীর ভাষা বাংলা, তাই
        // তালিকায় বাংলা নামটাই দেখায় — ইংরেজি নাম ধরে assert করলে
        // পরীক্ষাটা ভাষার উপর নির্ভর করত, খোঁজার উপর নয়।
        $code = Customer::query()->where('name_en', 'Bhara Store')->firstOrFail()->code;

        // দুই রূপেই খোঁজা — যেভাবে দুই কীবোর্ড পাঠায়
        foreach ([self::DECOMPOSED, self::PRECOMPOSED] as $spelling) {
            $this->actingAs($this->user)
                ->get(route('customer.index', ['q' => $spelling]))
                ->assertOk()
                ->assertSee($code);
        }

        // আর যেটা মেলে না সেটা যেন না আসে — নাহলে উপরের assert
        // শুধু "তালিকায় কিছু আছে" প্রমাণ করত
        $this->actingAs($this->user)
            ->get(route('customer.index', ['q' => 'নেইকিছু']))
            ->assertOk()
            ->assertDontSee($code);
    }

    public function test_what_reaches_the_database_is_always_the_same_form(): void
    {
        foreach ([self::PRECOMPOSED, self::DECOMPOSED] as $index => $spelling) {
            $this->actingAs($this->user)->post(route('customer.store'), [
                'name_en' => "Store {$index}",
                'name_bn' => $spelling,
                'opening_balance' => '0',
            ])->assertSessionHasNoErrors();
        }

        // কেবল এই টেস্টের বানানো দুইটা — ডেমো ডাটাতেও গ্রাহক আছে, আর
        // সেখানে একজন যোগ হলেই এই গোনাটা ভাঙত, অথচ স্বাভাবিকীকরণে
        // কিছুই বদলায়নি
        $names = Customer::query()
            ->where('name_en', 'like', 'Store %')
            ->orderBy('name_en')
            ->pluck('name_bn')
            ->all();

        $this->assertCount(2, $names);
        // দুইভাবে টাইপ করা হলেও ডাটাবেজে একই বাইট — নাহলে দুইটা আলাদা
        // গ্রাহক তৈরি হত যাদের নাম দেখতে হুবহু এক
        $this->assertSame($names[0], $names[1]);
        $this->assertTrue(Normalizer::isNormalized($names[0], Normalizer::FORM_C));
    }

    public function test_the_chart_of_accounts_finds_a_bangla_account_either_way(): void
    {
        app(StandardChart::class)->install();

        foreach ([self::PRECOMPOSED, self::DECOMPOSED] as $spelling) {
            $this->actingAs($this->user)
                ->get(route('accounts.coa.index', ['q' => $spelling]))
                ->assertOk()
                // ৫২০২ ভাড়া — প্রমিত ছকের খাত
                ->assertSee('5202');
        }
    }

    public function test_a_password_is_never_normalised(): void
    {
        // পাসওয়ার্ড বদলে দিলে পুরনো হ্যাশের সাথে আর মিলত না। এখানে
        // একক-অক্ষর রূপে পাসওয়ার্ড দিয়ে লগইন ব্যর্থ হওয়াই প্রমাণ যে
        // মিডলওয়্যার ওটা ছোঁয়নি — ছুঁলে ভাঙা রূপে বদলে যেত, আর তখন
        // দুইটা আলাদা পাসওয়ার্ড একই হয়ে যেত।
        $raw = 'পাসওয়ার্ড'.self::PRECOMPOSED;

        $user = User::factory()->create(['password' => bcrypt($raw)]);

        $this->post(route('login'), ['identifier' => $user->email, 'password' => $raw]);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * ডাটাবেজে যা আছে তার সবই এক রূপে।
     *
     * সিডার ও প্রমিত ছক মিডলওয়্যারের বাইরে দিয়ে লেখে (HTTP নয়), তাই
     * ওগুলোর লেখাও একই রূপে আছে কি না তা আলাদা করে দেখা দরকার —
     * নাহলে ব্যবহারকারীর টাইপ করা নাম আর সিস্টেমের বসানো নাম দুই রূপে
     * থাকত, আর একটা দিয়ে অন্যটা খুঁজে পাওয়া যেত না।
     */
    public function test_every_stored_string_in_the_database_is_normalised(): void
    {
        app(StandardChart::class)->install();

        $offenders = [];

        // চলতি স্কিমা স্পষ্ট করে বলা — নাহলে পাশের ডেটাবেসের টেবিলও
        // তালিকায় আসে, আর তখন এখানে নেই এমন টেবিল পড়তে গিয়ে ভাঙত
        $listing = Schema::getTableListing(
            schema: Schema::getCurrentSchemaName(),
            schemaQualified: false,
        );

        foreach ($listing as $table) {
            $columns = collect(Schema::getColumns($table))
                ->filter(fn (array $c) => in_array(
                    $c['type_name'],
                    ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'],
                    true,
                ))
                ->pluck('name')
                ->all();

            if ($columns === []) {
                continue;
            }

            foreach (DB::table($table)->get($columns) as $row) {
                foreach ((array) $row as $column => $value) {
                    if (is_string($value) && $value !== ''
                        && ! Normalizer::isNormalized($value, Normalizer::FORM_C)) {
                        $offenders[] = "{$table}.{$column}: {$value}";
                    }
                }
            }
        }

        $this->assertSame([], array_slice($offenders, 0, 10), implode("\n", $offenders));
    }
}
