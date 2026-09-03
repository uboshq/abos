<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Engines\Duplication\DuplicationRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক দরজা, সতেরো তালিকা — MasterListService দিয়ে যাওয়া প্রতিটা মাস্টারে
 * একই নামে দুইটা সারি আর বসে না।
 *
 * সবচেয়ে জরুরি: (১) নাম মিললে সতর্ক করে থামে; (২) টিক দিলে এগোয়, কিন্তু
 * নীরবে নয় — অডিটে বসে; (৩) MasterData/module.php-এর ঘোষণা সত্যিই
 * রেজিস্ট্রিতে পৌঁছেছে (নাহলে দরজাটা চুপচাপ খোলা থাকত)।
 */
class MasterDataDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ DemoSeeder নিজেই installDefaults() ডাকে — সেটা create() দিয়ে যায়,
        // অর্থাৎ setUp সফল হওয়াই প্রমাণ করে নতুন দরজা seed ভাঙে না।
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    private function lists(): MasterListService
    {
        return app(MasterListService::class);
    }

    /** ★ একই নামে দুইটা মাস্টার নয় — দ্বিতীয়টা সতর্ক করে থামে। */
    public function test_a_duplicate_master_name_is_blocked(): void
    {
        $this->lists()->create(Brand::class, ['name_en' => 'Nestle', 'name_bn' => 'নেসলে'], 'brands');

        $this->expectException(ValidationException::class);
        $this->lists()->create(Brand::class, ['name_en' => 'Nestle', 'name_bn' => 'অন্য'], 'brands');
    }

    /** normalization-পরে মিললেও ধরা পড়ে — "Nestle" vs "nestle." */
    public function test_a_normalised_match_is_caught(): void
    {
        $this->lists()->create(Brand::class, ['name_en' => 'Nestle', 'name_bn' => 'নেসলে'], 'brands');

        $this->expectException(ValidationException::class);
        $this->lists()->create(Brand::class, ['name_en' => 'nestle.', 'name_bn' => 'ভিন্ন'], 'brands');
    }

    /** ★ টিক দিলে এগোয় — কিন্তু নীরবে নয়, অডিটে "overridden" বসে। */
    public function test_the_override_goes_through_and_is_audited(): void
    {
        $this->lists()->create(Brand::class, ['name_en' => 'Unilever', 'name_bn' => 'ইউনিলিভার'], 'brands');

        $second = $this->lists()->create(
            Brand::class,
            ['name_en' => 'Unilever', 'name_bn' => 'ইউনিলিভার ২', 'allow_duplicate' => true],
            'brands',
        );

        $this->assertTrue(
            $second->auditTrail()->where('action', 'overridden')->exists(),
            'জোর করে বসানো ডুপ্লিকেট অডিটে চিহ্ন রাখেনি — নীরব override।',
        );
    }

    /** ★ ঘোষণা সত্যিই রেজিস্ট্রিতে পৌঁছেছে — Brand declared, CostCenter এখনো নয়। */
    public function test_the_masterlist_masters_are_declared_but_costcenter_waits(): void
    {
        $declared = app(DuplicationRegistry::class)->declaredModels();

        $this->assertContains(Brand::class, $declared, 'Brand ঘোষণা রেজিস্ট্রিতে পৌঁছায়নি — দরজা চুপচাপ খোলা।');
        $this->assertNotContains(CostCenter::class, $declared, 'CostCenter এখনো Accounts/module.php-এ ঘোষণার অপেক্ষায়।');
    }
}
