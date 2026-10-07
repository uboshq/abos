<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পক্ষের ধরন — নতুন ইনস্টল মালিকের চূড়ান্ত তালিকা নিয়ে জন্মায়, আর
 * ব্যবহৃত ধরন ভুলেও মুছে ফেলা যায় না।
 *
 * সবচেয়ে জরুরি তিনটা: (১) সঠিক তালিকা (পরিবেশক ডিফল্ট, ভোক্তা/কুরিয়ার/সার্ভিস
 * আছে, ডিলার/ভাড়া/দালাল নেই); (২) ডিফল্ট ঠিক একটা; (৩) কোনো গ্রাহক যে ধরনে
 * আছেন সেটা মুছলে সে চুপচাপ যায় না — remove-guard আটকায়।
 */
class PartyTypeSeedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    /** ★ নতুন ইনস্টলের তালিকা মালিকের চূড়ান্ত রূপ। */
    public function test_the_seeded_list_matches_the_owners_final_shape(): void
    {
        $codes = PartyType::query()->pluck('code')->all();

        // গ্রাহকের সিঁড়ি + ভোক্তা + প্রতিষ্ঠান
        foreach (['DISTRIB', 'WHOLE', 'RETAIL', 'CONSUMER', 'INST'] as $code) {
            $this->assertContains($code, $codes, "গ্রাহক-পক্ষ {$code} তালিকায় নেই।");
        }
        // সরবরাহকারী + নতুন দুইটা
        foreach (['VENDOR', 'TRANSPORT', 'LABOUR', 'COURIER', 'SERVICE'] as $code) {
            $this->assertContains($code, $codes, "সরবরাহকারী-পক্ষ {$code} তালিকায় নেই।");
        }
        // ⛔ বাদ-দেওয়াগুলো নতুন ইনস্টলে জন্মায় না
        foreach (['DEALER', 'RENTAL', 'BROKER'] as $code) {
            $this->assertNotContains($code, $codes, "বাদ-দেওয়া পক্ষ {$code} এখনো তালিকায়।");
        }

        // প্রতিষ্ঠান দুই দিকেই, ভোক্তা কেবল গ্রাহক
        $this->assertSame(PartyType::BOTH, PartyType::query()->where('code', 'INST')->value('applies_to'));
        $this->assertSame(PartyType::CUSTOMER, PartyType::query()->where('code', 'CONSUMER')->value('applies_to'));
    }

    /** ★ ডিফল্ট ঠিক একটা — পরিবেশক (শূন্য বা দুইটা নয়)। */
    public function test_distributor_is_the_only_default(): void
    {
        $defaults = PartyType::query()->where('is_default', true)->pluck('code')->all();

        $this->assertSame(['DISTRIB'], $defaults, 'ডিফল্ট পক্ষ ঠিক একটা "DISTRIB" হওয়ার কথা।');
    }

    /** ★ ব্যবহৃত ধরন মুছলে চুপচাপ যায় না — remove-guard নিষ্ক্রিয় করে, forceDelete নয়। */
    public function test_a_party_type_in_use_is_not_hard_deleted(): void
    {
        $whole = PartyType::query()->where('code', 'WHOLE')->firstOrFail();

        // একজন গ্রাহক ওই ধরনে
        Customer::query()->create([
            'code' => 'C-GUARD',
            'name_en' => 'Guard Shop',
            'party_type_id' => $whole->id,
        ]);

        $removed = app(MasterListService::class)->delete($whole);

        $this->assertFalse($removed, 'ব্যবহৃত পক্ষ forceDelete হয়ে গেছে — remove-guard আটকায়নি।');
        $this->assertNotNull(PartyType::query()->where('code', 'WHOLE')->first(), 'WHOLE টেবিল থেকে মুছে গেছে।');
        $this->assertFalse((bool) PartyType::query()->where('code', 'WHOLE')->value('is_active'),
            'ব্যবহৃত পক্ষ মুছতে গিয়ে অন্তত নিষ্ক্রিয় হওয়ার কথা।');
    }
}
