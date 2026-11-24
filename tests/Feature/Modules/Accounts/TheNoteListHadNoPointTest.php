<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নোটের তালিকায় পয়েন্ট ছিল না, আর তারিখের ঘরের শিরোনাম ছিল "কারণ" — মালিকের ছবি, ৩ অক্টোবর ২০২৬।
 *
 * ⭐ "কাকে"-র পরেই পয়েন্ট (মালিকের নিয়ম, ২৮ সেপ্টেম্বর ২০২৬: সব তালিকায়); পক্ষ আর পয়েন্ট দিয়ে খোঁজা আর সাজানো।
 * আর "তৈরি করুন" মেনুতে ডেবিট নোট, ক্রেডিট নোট — নোট বানানোর চাবি যাঁর আছে কেবল তাঁর।
 *
 * ⓘ তালিকা এখন ভাগ ধরে নিজের পাতায় (নকশা v5, ২৩ নভেম্বর ২০২৬) — দাবিগুলো ক্রেডিট নোটের তালিকায়; পুরনো `?direction=` ঠিকানা সেখানে ফেরায়।
 */
final class TheNoteListHadNoPointTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    public function test_the_point_follows_the_party_and_the_date_has_its_own_header(): void
    {
        [, $omega] = $this->twoShops();

        $html = $this->get(route('accounts.note.cn.index'))->assertOk()->getContent();

        $party = mb_strpos($html, '>'.__('accounts::note.party').'<');
        $point = mb_strpos($html, '>'.__('accounts::note.point').'<');

        $this->assertNotFalse($point, '⛔ তালিকায় পয়েন্টের ঘর নেই।');
        $this->assertGreaterThan($party, $point, '⛔ পয়েন্ট "কাকে"-র পরে নয়।');
        $this->assertStringContainsString('Zeta Bazar Point', $html, '⛔ সারিতে গ্রাহকের পয়েন্ট নেই।');
        $this->assertStringContainsString('>'.__('core.print.date').'<', $html, '⛔ তারিখের ঘরের শিরোনাম তারিখ নয়।');
        // ⓘ তালিকার মাথা ধরে — ডান পাশের ছাঁকনির প্যানেলেও "কারণ" আছে (২৩ নভেম্বর ২০২৬)
        preg_match('~<thead\b.*?</thead>~s', $html, $head);
        $this->assertSame(1, preg_match_all('~>\s*'.preg_quote(__('accounts::note.reason'), '~').'\s*<~u', $head[0] ?? ''), '⛔ "কারণ" শিরোনাম দুইবার।');
        unset($omega);
    }

    public function test_the_list_searches_by_party_and_by_point(): void
    {
        [$alpha, $omega] = $this->twoShops();

        $this->get(route('accounts.note.cn.index', ['q' => 'Zeta Bazar']))->assertOk()
            ->assertSee($omega->document_no)->assertDontSee($alpha->document_no);

        $this->get(route('accounts.note.cn.index', ['q' => 'Alpha Shop']))->assertOk()
            ->assertSee($alpha->document_no)->assertDontSee($omega->document_no);
    }

    public function test_the_list_sorts_by_point_and_by_party(): void
    {
        [$alpha, $omega] = $this->twoShops();

        // ⓘ প্রস্তুতি: "নতুন আগে"-তে Omega আগে — নিচের দুই ক্রম এর উল্টো, তাই সাজানো সত্যিই কিছু করেছে কি না ধরা পড়ে
        $html = $this->get(route('accounts.note.cn.index'))->getContent();
        $this->assertLessThan(strpos($html, $alpha->document_no), strpos($html, $omega->document_no));

        $html = $this->get(route('accounts.note.cn.index', ['sort' => 'point']))->getContent();
        $this->assertLessThan(strpos($html, $omega->document_no), strpos($html, $alpha->document_no), '⛔ পয়েন্ট ধরে সাজানো হয়নি।');

        $html = $this->get(route('accounts.note.cn.index', ['sort' => 'party']))->getContent();
        $this->assertLessThan(strpos($html, $omega->document_no), strpos($html, $alpha->document_no), '⛔ পক্ষ ধরে সাজানো হয়নি।');
    }

    public function test_the_create_menu_offers_both_notes_only_with_the_key(): void
    {
        $debit = route('accounts.note.dn.create');
        $credit = route('accounts.note.cn.create');

        $this->get(route('dashboard'))->assertOk()->assertSee($debit, false)->assertSee($credit, false);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo('accounts.note.view');

        $this->actingAs($clerk)->get(route('dashboard'))->assertOk()->assertDontSee($debit, false);

        $clerk->givePermissionTo('accounts.note.manage');
        $this->actingAs($clerk->fresh())->get(route('dashboard'))->assertOk()->assertSee($debit, false)->assertSee($credit, false);
    }

    /** @return array{0: Note, 1: Note} ক: Alpha Shop @ Alpha Bazar (আগে বানানো) ; খ: Omega Shop @ Zeta Bazar */
    private function twoShops(): array
    {
        $notes = [];

        // ⓘ ক্রম ইচ্ছাকৃত: আগে বানানো পক্ষ নামে আর পয়েন্টে দুটোতেই আগে — তাই "নতুন আগে" আর নাম/পয়েন্টের ক্রম উল্টো
        foreach ([['Alpha Shop', 'Alpha Bazar Point', 'PT-A'], ['Omega Shop', 'Zeta Bazar Point', 'PT-Z']] as [$shop, $place, $code]) {
            $point = Location::query()->create([
                'company_id' => $this->company->id, 'code' => $code, 'level' => Location::POINT,
                'name_en' => $place, 'name_bn' => $place, 'is_active' => true,
            ]);

            $customer = Customer::query()->create([
                'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'code' => 'NL-'.$code, 'name_en' => $shop, 'name_bn' => $shop, 'location_id' => $point->id, 'is_active' => true,
            ]);

            $notes[] = app(NoteService::class)->create([
                'direction' => Note::CREDIT, 'party_type' => 'customer', 'party_id' => $customer->id,
                'trx_date' => now()->toDateString(), 'amount' => '100', 'tax_amount' => '0', 'reason' => 'price_correction',
            ]);
        }

        return $notes;
    }
}
