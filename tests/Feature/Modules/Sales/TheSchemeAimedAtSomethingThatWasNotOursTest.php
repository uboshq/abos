<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Brand;
use App\Modules\Sales\Models\SalesTarget;
use App\Modules\Sales\Models\Scheme;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * স্কিমের লক্ষ্য আর টার্গেটের কর্মী — যা এই কোম্পানির নয় (চূড়ান্ত অডিট ⚠️, ১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * স্কিমের `target_id` কেবল "একটা পূর্ণসংখ্যা", আর টার্গেটের ফর্মের চাবি (কর্মীর নম্বর) কেউ দেখত না — বানানো
 * নম্বর বা অন্য কোম্পানির ব্র্যান্ড/লোক দিব্যি বসত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লক্ষ্য খোঁজা হয় কোম্পানির দেয়ালের ভিতরে ([[SchemeController::validated()]]), আর টার্গেট কেবল এই কোম্পানির
 * সদস্যের ([[SalesTargetService::setForMonth()]])। ⭐ সব দাবি মালিকের (super_admin) হাতে — নিজের জিনিসে তাঁর কাজ
 * আগের মতোই চলে।
 */
final class TheSchemeAimedAtSomethingThatWasNotOursTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->assertTrue($this->owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), 'দৃশ্যটাই বানানো যায়নি — মালিক super_admin নন।');

        // ⓘ মালিক দুই কোম্পানিতে — অনুরোধের কোম্পানি তাঁর চলতি কোম্পানি, তাই সেটাও এই কোম্পানি
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        $this->actingAs($this->owner->fresh());
    }

    /** ⛔ অন্য কোম্পানির ব্র্যান্ড বা বানানো নম্বর — স্কিম জন্মায় না; ⭐ নিজের ব্র্যান্ডে জন্মায়। */
    public function test_a_scheme_aims_only_at_this_companys_things(): void
    {
        // ⓘ অন্য কোম্পানির একটা ব্র্যান্ড — সরাসরি টেবিলে, যাতে এই কোম্পানির দেয়াল ওটা বসাতে না পারে
        $other = Company::query()->whereKeyNot($this->company->id)->value('id');
        $theirs = \Illuminate\Support\Facades\DB::table((new Brand())->getTable())->insertGetId([
            'company_id' => $other, 'code' => 'THEIR-BR', 'name_en' => 'Their brand', 'name_bn' => 'ওদের ব্র্যান্ড',
            'is_active' => true, 'public_id' => (string) \Illuminate\Support\Str::uuid7(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNull(Brand::query()->find($theirs), 'দৃশ্যটাই বানানো যায়নি — অন্য কোম্পানির ব্র্যান্ড এখানে দেখা যায়।');

        foreach ([$theirs, 999999] as $target) {
            $this->post(route('sales.scheme.store'), $this->scheme('Stranger '.$target, (int) $target))
                ->assertSessionHasErrors('target_id');
        }

        $this->assertSame(0, Scheme::query()->where('name', 'like', 'Stranger %')->count(), '⛔ অন্যের জিনিসে তাক করা স্কিম জন্মেছে।');

        $ours = Brand::query()->create(['code' => 'OUR-BR', 'name_en' => 'Our brand', 'name_bn' => 'আমাদের ব্র্যান্ড', 'is_active' => true])->id;
        $this->post(route('sales.scheme.store'), $this->scheme('Our brand', (int) $ours))->assertSessionHasNoErrors();
        $this->assertTrue(Scheme::query()->where('name', 'Our brand')->exists());
    }

    /** ⛔ অন্য কোম্পানির লোক বা বানানো নম্বরে টার্গেট — পুরো ফর্ম ফেরে; ⭐ নিজের কর্মীর টার্গেট বসে। */
    public function test_a_target_goes_only_to_this_companys_staff(): void
    {
        $stranger = User::factory()->create();
        $member = User::factory()->create(['current_company_id' => $this->company->id]);
        $member->companies()->attach($this->company->id, ['is_active' => true]);

        // ⓘ আলাদা আলাদা — একসাথে দিলে একটা বাধাই অন্যটার দাবি সবুজ করে দিত
        foreach ([$stranger->id, 999999] as $nobody) {
            $this->post(route('sales.target.store'), [
                'month' => now()->format('Y-m-01'),
                'amount' => [$member->id => '50000', $nobody => '90000'],
            ])->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, SalesTarget::query()->count(), '⛔ অচেনা লোকের নামে টার্গেট বসেছে (বা মাসটা আধা-বসানো)।');

        $this->post(route('sales.target.store'), [
            'month' => now()->format('Y-m-01'),
            'amount' => [$member->id => '50000'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, SalesTarget::query()->where('user_id', $member->id)->count());
    }

    /** @return array<string, mixed> */
    private function scheme(string $name, int $target): array
    {
        return [
            'name' => $name,
            'basis' => Scheme::VALUE,
            'applies_to' => Scheme::BRAND,
            'target_id' => $target,
            'valid_from' => now()->toDateString(),
            'valid_to' => now()->addMonth()->toDateString(),
        ];
    }
}
