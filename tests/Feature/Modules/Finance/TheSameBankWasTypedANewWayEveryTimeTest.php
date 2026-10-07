<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\InstitutionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই ব্যাংক প্রতিবার নতুন বানানে লেখা হত।
 *
 * ── কী ছিল ──────────────────────────────────────────────────────────
 * ব্যাংক সুবিধা আর আমানতের ফর্মে ব্যাংকের নাম ছিল মুক্ত-লেখা ঘর।
 * "Islami Bank Bangladesh Ltd.", "Islami Bank", "IBBL" — একই ব্যাংক
 * তিনটা নামে, আর "এই ব্যাংকে আমাদের মোট কত" প্রশ্নের উত্তর দেওয়ার
 * কোনো উপায় ছিল না।
 *
 * ⭐ এখন একটা তালিকা (কেবল অর্থ মডিউলে — মালিকের সিদ্ধান্ত), আর এই
 * ফাইল পাহারা দেয় যে তালিকাটা নিজেই নকলে ভরে না যায়।
 */
final class TheSameBankWasTypedANewWayEveryTimeTest extends TestCase
{
    use RefreshDatabase;

    private InstitutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->service = app(InstitutionService::class);
    }

    /**
     * ⭐ মূলধনের পাতার ধাঁচ — তালিকার পাতায় তালিকা আর ধরনের ট্যাব;
     * "+ নতুন" নিজের পাতায় নেয়, ফর্ম তালিকার মাঝে নয়।
     */
    public function test_the_list_has_kind_tabs_and_new_goes_to_its_own_page(): void
    {
        $this->bank('Islami Bank Bangladesh Ltd.', Institution::BANK);
        $this->bank('Pragati Life Insurance', Institution::INSURANCE);

        $all = $this->get(route('finance.institution.index'))->assertOk();
        $all->assertSee('Islami Bank Bangladesh Ltd.');
        $all->assertSee('Pragati Life Insurance');
        $all->assertSee(route('finance.institution.create'), escape: false);

        /*
         * ⚠️ "তালিকার পাতায় ফর্ম নেই" — কিন্তু জমার ঠিকানা খুঁজে সেটা মাপা
         * যায় না: `POST /finance/institutions` আর তালিকার নিজের ঠিকানা
         * হুবহু এক, কেবল পদ্ধতি আলাদা। ⓘ তাই ফর্মের ঘরটাই খোঁজা হয় —
         * ওটা কেবল লেখার পাতায় থাকে।
         */
        $all->assertDontSee('name="name_en"', escape: false);

        foreach (Institution::KINDS as $kind) {
            $all->assertSee(route('finance.institution.index', ['kind' => $kind]), escape: false);
        }

        $insurers = $this->get(route('finance.institution.index', ['kind' => Institution::INSURANCE]))->assertOk();
        $insurers->assertSee('Pragati Life Insurance');
        $insurers->assertDontSee('Islami Bank Bangladesh Ltd.');

        $this->get(route('finance.institution.create', ['kind' => Institution::INSURANCE]))
            ->assertOk()
            ->assertSee(route('finance.institution.store'), escape: false);
    }

    /**
     * ⭐ ফর্ম থেকে বসানো, বদলানো আর বন্ধ করা — মোছা নয়।
     */
    public function test_an_institution_is_added_edited_and_switched_off_from_its_screens(): void
    {
        $this->post(route('finance.institution.store'), [
            'kind' => Institution::BANK,
            'name_en' => '  Dutch-Bangla Bank PLC ',
            'short_code' => 'DBBL',
            'branch_name' => 'Motijheel',
            'phone' => '',
        ])->assertRedirect(route('finance.institution.index', ['kind' => Institution::BANK]));

        $dbbl = Institution::query()->where('short_code', 'DBBL')->firstOrFail();

        $this->assertSame('Dutch-Bangla Bank PLC', $dbbl->name_en, 'নামের দুই পাশের ফাঁকা থেকে গেছে।');
        $this->assertNull($dbbl->phone, 'খালি ঘর খালি লেখা হয়ে বসেছে, null নয়।');
        $this->assertTrue($dbbl->is_active);

        $this->get(route('finance.institution.edit', $dbbl))->assertOk()->assertSee('Motijheel');

        $this->put(route('finance.institution.update', $dbbl), [
            'kind' => Institution::BANK,
            'name_en' => 'Dutch-Bangla Bank PLC',
            'name_bn' => 'ডাচ্-বাংলা ব্যাংক',
            'branch_name' => 'Dilkusha',
        ])->assertRedirect();

        $this->assertSame('Dilkusha', $dbbl->fresh()->branch_name,
            'নিজের নামেই বদলাতে গিয়ে "এই নামে আগেই আছে" বলে থেমে গেছে।');

        $this->patch(route('finance.institution.toggle', $dbbl))->assertRedirect();

        $this->assertFalse($dbbl->fresh()->is_active);
        $this->assertNotNull(Institution::query()->find($dbbl->id), 'বন্ধ করতে গিয়ে সারিটা মুছে গেছে।');
    }

    /**
     * ⛔ একই নাম বড়-ছোট হাতের অক্ষর, দাঁড়ি-কমা বা বাড়তি ফাঁকায় আলাদা
     * হলেও দ্বিতীয়বার বসে না — আর বার্তাটা বলে কোনটা আগে থেকে আছে।
     */
    public function test_the_same_name_spelled_a_little_differently_is_refused(): void
    {
        $this->bank('Islami Bank Bangladesh Ltd.', Institution::BANK);

        $this->post(route('finance.institution.store'), [
            'kind' => Institution::BANK,
            'name_en' => 'islami  bank bangladesh ltd',
        ])->assertSessionHasErrors('name_en');

        $this->assertSame(1, Institution::query()->count(), 'একই ব্যাংক দুইবার তালিকায় বসে গেছে।');

        try {
            $this->service->create(['kind' => Institution::BANK, 'name_en' => 'ISLAMI BANK, BANGLADESH LTD']);
            $this->fail('কমা দিয়ে লেখা একই নাম পাহারা পেরিয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Islami Bank Bangladesh Ltd.', $e->errors()['name_en'][0],
                'বার্তা বলেনি কোন নামটা আগে থেকে আছে।');
        }
    }

    /**
     * ⭐ অন্য কোম্পানির একই নামের ব্যাংক বাধা নয় — তালিকা প্রতিটা
     * কোম্পানির নিজের।
     */
    public function test_another_company_may_list_the_same_bank(): void
    {
        $this->bank('Sonali Bank PLC', Institution::BANK);

        $other = Company::query()->whereKeyNot(CompanyContext::id())->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $theirs = $this->service->create(['kind' => Institution::BANK, 'name_en' => 'Sonali Bank PLC']);

        $this->assertSame($other->id, $theirs->company_id);
    }

    /**
     * ⭐ ফর্মের বাছাই-ঘর: তালিকা থেকে বাছা, নতুন নাম লেখা — আর তালিকায়
     * আগে থেকে থাকা নাম লিখলে সেটাই নেওয়া হয়, দ্বিতীয় সারি নয়।
     */
    public function test_the_picker_reuses_a_listed_name_and_adds_a_new_one(): void
    {
        $ibbl = $this->bank('Islami Bank Bangladesh Ltd.', Institution::BANK);

        $data = ['institution_id' => $ibbl->id];
        $this->assertSame($ibbl->id, $this->service->resolve($data, Institution::BANK));

        $data = ['institution_id' => '', 'institution_new' => 'Islami Bank Bangladesh Ltd'];
        $this->assertSame($ibbl->id, $this->service->resolve($data, Institution::BANK),
            'তালিকায় থাকা নাম টাইপ করায় দ্বিতীয় সারি বসেছে।');
        $this->assertArrayNotHasKey('institution_new', $data, 'লেখা নামটা ডেটায় থেকে গেছে।');

        $data = ['institution_new' => 'IDLC Finance PLC'];
        $idlc = Institution::query()->findOrFail($this->service->resolve($data, Institution::NBFI));
        $this->assertSame(Institution::NBFI, $idlc->kind, 'নতুন নামটা ফর্মের ধরনে বসেনি।');

        $data = [];
        $this->assertNull($this->service->resolve($data, Institution::BANK));

        $this->expectException(ValidationException::class);
        $data = ['institution_id' => $ibbl->id, 'institution_new' => 'Something Else'];
        $this->service->resolve($data, Institution::BANK);
    }

    /**
     * ⭐ ধাপ ৩ — নতুন ব্যাংক সুবিধা তালিকা থেকে ব্যাংক নেয়, আর তালিকায়
     * না থাকলে সেখানেই যোগ করে। ⓘ পুরনো `bank` ঘরটাও ভরে থাকে, যাতে
     * পুরনো সারি আর নতুন সারি এক রকম পড়া যায়।
     */
    public function test_a_new_bank_facility_takes_its_bank_from_the_list(): void
    {
        $facility = app(BankFacilityService::class)->open([
            'kind' => BankFacility::GUARANTEE,
            'institution_new' => 'Eastern Bank PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '2000000.0000',
            'margin_percent' => '15.00',
        ]);

        $ebl = Institution::query()->where('name_en', 'Eastern Bank PLC')->firstOrFail();

        $this->assertSame($ebl->id, (int) $facility->institution_id, 'সুবিধাটা প্রতিষ্ঠানে বসেনি।');
        $this->assertSame('Eastern Bank PLC', $facility->bank, 'পুরনো নামের ঘরটা ফাঁকা থেকে গেছে।');
        $this->assertSame(Institution::BANK, $ebl->kind);

        $second = app(BankFacilityService::class)->open([
            'kind' => BankFacility::GUARANTEE,
            'institution_id' => $ebl->id,
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '500000.0000',
            'margin_percent' => '10.00',
        ]);

        $this->assertSame($ebl->id, (int) $second->institution_id);
        $this->assertSame(1, Institution::query()->where('name_key', Institution::keyFor('Eastern Bank PLC'))->count(),
            'একই ব্যাংক দুইবার তালিকায় বসেছে।');
    }

    private function bank(string $name, string $kind): Institution
    {
        return $this->service->create(['kind' => $kind, 'name_en' => $name]);
    }
}
