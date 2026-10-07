<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কার কত মালিকানা — প্রশ্নটার নিজের কোনো জায়গা ছিল না।
 *
 * ── ⭐ মালিকের প্রস্তাব, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"মূলধন ও বিনিয়োগের ভিতরে একটা ট্যাবে 'মালিক ও বিনিয়োগকারী'…
 * বাকি বোতামগুলোতেও একই ভাবে।"*
 *
 * ⓘ আগে "কে কোথায় দাঁড়িয়ে" আর সারির ইতিহাস এক পাতায় ওপর-নিচে ছিল।
 * এখন দুইটা ট্যাব, আর নামে ক্লিক করলে কেবল তাঁর সারি।
 *
 * ⚠️ সাথে একটা পাহারা: অংশীদারদের অংশ মিলে ১০০ না হলে পাতা বলে কতটা
 * কারও নামে নেই — অংশীদারি ব্যবসায় ঝগড়াটা ঠিক এই সংখ্যা নিয়েই।
 */
final class WhoOwnsWhatHadNoPlaceOfItsOwnTest extends TestCase
{
    use RefreshDatabase;

    private Person $karim;

    private Person $rahim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->karim = $this->person('P-KARIM', 'Karim Partner');
        $this->rahim = $this->person('P-RAHIM', 'Rahim Partner');

        $this->entry($this->karim, 'CAP-T1', '100000', '40');
        $this->entry($this->rahim, 'CAP-T2', '150000', '50');
    }

    /**
     * ⭐ দুইটা ট্যাব, আর মালিক ও বিনিয়োগকারী ট্যাবে প্রতি জনে এক সারি।
     */
    public function test_the_owners_tab_lists_each_partner_once(): void
    {
        $page = $this->get(route('finance.capital.index', ['tab' => 'owners']));

        $page->assertOk();
        $page->assertSeeInOrder([__('finance::field.tab_entries'), __('finance::field.owners_investors')]);

        $names = collect($page->viewData('positions'))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Karim Partner', 'Rahim Partner'], $names);
        $page->assertSee('Karim Partner');
    }

    /**
     * ⭐ ৪০% + ৫০% = ৯০% — পাতা বলে ১০% কারও নামে নেই।
     */
    public function test_a_share_total_short_of_100_is_called_out(): void
    {
        $page = $this->get(route('finance.capital.index', ['tab' => 'owners']));

        $page->assertSee(__('finance::message.share_total_off', ['total' => '90', 'gap' => '10']));
    }

    /**
     * ⭐ ঠিক ১০০ হলে কিছু বলা হয় না — অকারণ সতর্কবার্তা মানুষকে উপেক্ষা করতে শেখায়।
     */
    public function test_a_full_100_says_nothing(): void
    {
        $this->entry($this->person('P-THIRD', 'Third Partner'), 'CAP-T3', '10000', '10');

        $page = $this->get(route('finance.capital.index', ['tab' => 'owners']));

        $page->assertOk();

        // ⓘ বার্তার শুরুর লেখাটুকু — অঙ্ক যা-ই হোক, এটা কেবল সতর্কবার্তায় থাকে
        $this->assertStringNotContainsString(
            strtok(__('finance::message.share_total_off', ['total' => 'X', 'gap' => 'Y']), 'X'),
            $page->getContent(),
            'অংশ মিলে ১০০, তবু সতর্কবার্তা এসেছে।',
        );
    }

    /**
     * ⭐ রাউন্ডিংয়ে মিথ্যা বার্তা নয় — abos-8b-এর ধরা (১৯ সেপ্টেম্বর)।
     *
     * ⓘ তিনজনের অংশ ৩৩.৩৩৩৩ করে হাতে লেখা — যোগ ৯৯.৯৯৯৯। ⚠️ ছাড় না থাকলে
     * পাতা বলত "০.০০০১% কারও নামে নেই", আর মানুষ আসল বার্তাটাও উপেক্ষা
     * করতে শিখতেন।
     */
    public function test_a_rounding_hair_is_not_called_out(): void
    {
        CapitalEntry::query()->delete();

        foreach (['A', 'B', 'C'] as $i => $who) {
            $this->entry($this->person('P-EQ'.$who, 'Equal '.$who), 'CAP-EQ'.$i, '10000', '33.3333');
        }

        $page = $this->get(route('finance.capital.index', ['tab' => 'owners']));

        $page->assertOk();

        $this->assertStringNotContainsString(
            strtok(__('finance::message.share_total_off', ['total' => 'X', 'gap' => 'Y']), 'X'),
            $page->getContent(),
            'অংশের যোগ ৯৯.৯৯৯৯ — রাউন্ডিং মাত্র, তবু সতর্কবার্তা এসেছে।',
        );
    }

    /**
     * ⭐ নামে ক্লিক — লেনদেন ট্যাবে কেবল তাঁর সারি।
     */
    public function test_clicking_a_name_shows_only_their_rows(): void
    {
        $page = $this->get(route('finance.capital.index', ['tab' => 'owners']));
        $page->assertSee(route('finance.capital.index', ['person' => $this->karim->id]), escape: false);

        $rows = $this->get(route('finance.capital.index', ['person' => $this->karim->id]));

        $rows->assertOk();

        $docs = collect($rows->viewData('entries')->items())->pluck('document_no')->all();

        $this->assertSame(['CAP-T1'], $docs,
            'একজনের নামে ক্লিক করলে অন্যের সারিও এসেছে।');

        $rows->assertSee(__('finance::message.showing_one_person', ['name' => 'Karim Partner']));
    }

    private function person(string $code, string $name): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $name,
            'is_active' => true,
        ]);
    }

    private function entry(Person $person, string $no, string $amount, string $share): void
    {
        CapitalEntry::query()->create([
            'company_id' => CompanyContext::id(),
            'document_no' => $no,
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'share_percent' => $share,
            'status' => CapitalEntry::POSTED,
        ]);
    }
}
