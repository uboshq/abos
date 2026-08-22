<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ধ্রুবকটা ছিল, ছাঁকনিটা ছিল না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `UserDataScope::WAREHOUSE` প্রথম দিন থেকেই ঘোষিত, মন্তব্যসহ:
 * *"গুদাম — মজুদের কাগজে"*। কিন্তু গোটা প্রকল্পে **একটাও কোয়েরি ওটা
 * পড়ত না** — `grep` করলে নিজের সংজ্ঞা ছাড়া আর কোথাও নাম মিলত না।
 *
 * অর্থাৎ কেউ সারি বসাতে পারতেন, সারিটা ডাটাবেজে বসত, আর কিছুই হত না।
 * নীরবে। এটাই এই প্রকল্পের সবচেয়ে বারবার ফেরা ভুল: **ধ্রুবকটা আছে বলে
 * জিনিসটা আছে বলে মনে হয়**।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * প্রথমটা: সীমা না বসালে সব দেখা যায়। শাখার দেয়ালের মতোই — উল্টো
 * ধরলে ফিচারটা চালুর দিনে প্রতিটা স্টোরকিপার অন্ধ হয়ে যেতেন।
 *
 * দ্বিতীয়টা: **গুদামের নিজের তালিকাও ছাঁকা হয়**। ওখানে ঘরটার নাম
 * `warehouse_id` নয়, `id` — একটা লাইন ভুলে গেলে মজুদের সংখ্যা ছাঁকা
 * হত অথচ ড্রপডাউনে সব গুদামের নাম থেকে যেত, আর সীমাবদ্ধ মানুষটা এমন
 * গুদাম বাছতে পারতেন যার একটাও সারি তিনি দেখতে পান না।
 */
class AConstantThatFilteredNothingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Warehouse $main;

    private Warehouse $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->main = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $this->second = Warehouse::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'WH-2',
            'name_en' => 'Second store',
            'name_bn' => 'দ্বিতীয় গুদাম',
            'is_active' => true,
        ]);
    }

    private function limitTo(Warehouse $house): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'scope_type' => UserDataScope::WAREHOUSE,
            'scope_id' => $house->id,
        ]);

        app(DataScope::class)->forget();
    }

    /**
     * সীমা না বসালে সব দেখা যায়।
     *
     * শাখার দেয়ালের একই ভিত্তি। উল্টো দিকে ধরলে ফিচারটা চালুর দিনেই
     * সবাই অন্ধ হয়ে যেতেন, আর কেউ ঢুকে ঠিকও করতে পারতেন না।
     */
    public function test_a_person_with_no_limit_sees_every_warehouse(): void
    {
        $this->actingAs($this->owner);

        $this->assertFalse(app(DataScope::class)->isLimited($this->owner, UserDataScope::WAREHOUSE));
        $this->assertGreaterThanOrEqual(2, Warehouse::query()->count());
    }

    /**
     * সীমা বসালে গুদামের **নিজের তালিকাও** ছাঁকা হয়।
     *
     * এখানেই একটা লাইন ভুলে যাওয়া সবচেয়ে সহজ: ওই টেবিলে ঘরটার নাম
     * `warehouse_id` নয়, `id`। ছাঁকনিটা কেবল মজুদে বসালে ড্রপডাউনে
     * সব গুদামের নাম থেকে যেত।
     */
    public function test_the_warehouse_list_itself_is_filtered(): void
    {
        $this->limitTo($this->second);
        $this->actingAs($this->owner);

        $codes = Warehouse::query()->pluck('code')->all();

        $this->assertSame([$this->second->code], $codes,
            'গুদামের তালিকা ছাঁকা হয়নি — সীমাবদ্ধ মানুষ এমন গুদাম বাছতে পারবেন '.
            'যার একটাও সারি তিনি দেখতে পান না।');
    }

    /** মজুদের চলাচলও ছাঁকা হয়। */
    public function test_stock_movements_are_filtered_too(): void
    {
        $this->seedMovements();
        $this->limitTo($this->second);
        $this->actingAs($this->owner);

        $seen = StockMovement::query()->pluck('warehouse_id')->unique()->values()->all();

        $this->assertSame([$this->second->id], $seen,
            'অন্য গুদামের চলাচল দেখা যাচ্ছে — সীমাটা কেবল কাগজে।');
    }

    /**
     * সচেতনভাবে সীমা ছাড়ানো যায় — কিন্তু কেবল নাম ধরে ডাকলে।
     *
     * মাস শেষের মজুদ মেলানোর রিপোর্টে গোটা কোম্পানির সংখ্যা লাগে।
     * পথটা আছে, কিন্তু ওটা লিখতে হয় — নীরবে ঘটে না।
     */
    public function test_a_report_can_step_over_the_wall_on_purpose(): void
    {
        $this->seedMovements();
        $this->limitTo($this->second);
        $this->actingAs($this->owner);

        /*
         * নিজের সারিগুলো ধরে গোনা, মোট নয়।
         *
         * ডেমো সিডার নিজেও চলাচল বানায়, তাই "মোট দুইটা" দাবিটা
         * প্রথমবার ১৩ পেয়ে ভেঙেছিল। দাবিটা আসলে ছিল **দেয়ালটা
         * ডিঙানো গেল কি না** — সেটা মাপতে নিজের দুইটা কাগজই যথেষ্ট,
         * আর ওটাই সিডার বদলালেও টিকে থাকে।
         */
        $mine = fn (bool $across) => ($across
            ? StockMovement::acrossWarehouses()
            : StockMovement::query())
            ->where('document_no', 'like', 'WH-TEST-%')->count();

        $this->assertSame(1, $mine(false), 'সীমার ভেতরে একটার বেশি দেখা যাচ্ছে।');
        $this->assertSame(2, $mine(true), 'সচেতনভাবে ডাকলেও দেয়ালটা ছাড়ানো গেল না।');

        $this->assertCount(1, Warehouse::query()->get());
        $this->assertGreaterThanOrEqual(2, Warehouse::acrossWarehouses()->count());
    }

    /**
     * ব্যবহারকারীর পর্দায় গুদামের ঘরগুলো সত্যিই আছে।
     *
     * ছাঁকনি বানিয়ে দরজা না বানালে সারিটা বসানোর একমাত্র পথ থাকত
     * ডাটাবেজে হাতে INSERT — আর সেটাই ছিল আগের অবস্থা।
     */
    public function test_the_user_screen_offers_the_warehouse_boxes(): void
    {
        $this->actingAs($this->owner)
            ->get(route('system_admin.user.edit', $this->owner))
            ->assertOk()
            ->assertSee('warehouse_scope['.$this->company->id.'][]', false)
            ->assertSee($this->second->code);
    }

    /** পর্দা থেকে বসানো সীমাটা সত্যিই খাটে। */
    public function test_saving_from_the_screen_actually_limits(): void
    {
        $this->seedMovements();

        $user = $this->owner->fresh(['roles', 'companies']);

        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale ?? 'bn',
                'is_active' => '1',
                'roles' => $user->roles->pluck('name')->all(),
                'companies' => $user->companies->pluck('id')->all(),
                'warehouse_scope' => [$this->company->id => [$this->second->id]],
            ])
            ->assertRedirect(route('system_admin.user.index'));

        app(DataScope::class)->forget();

        $this->assertSame(
            [$this->second->id],
            app(DataScope::class)->idsFor($this->owner, UserDataScope::WAREHOUSE),
        );
    }

    /** দুই গুদামে একটা করে চলাচল। */
    private function seedMovements(): void
    {
        foreach ([$this->main, $this->second] as $house) {
            StockMovement::query()->create([
                'company_id' => $this->company->id,
                'branch_id' => $this->company->defaultBranch()?->id,
                'warehouse_id' => $house->id,
                'product_id' => Product::query()->value('id'),
                'trx_date' => '2026-08-10',
                'floor_change' => '10',
                'source_type' => 'test',
                'source_id' => 0,
                'document_no' => 'WH-TEST-'.$house->code,
            ]);
        }
    }
}
