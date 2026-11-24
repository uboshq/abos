<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\GateMemo;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ পণ্যের তালিকায় চাবির প্রশ্ন একবার, উত্তর প্রতিটা সারিতে আগেরটাই (২৪ নভেম্বর ২০২৬; [[GateMemo::ofTheList()]])।
 *
 * দাবি: মালিক, হিসাবরক্ষক, মাঠকর্মী আর চাবিহীন — প্রতি সারিতে সম্পাদনা/নিষ্ক্রিয় ঠিক দরজার উত্তর; আসল পাতার মেনু আর সুইচও; দরজায়
 * প্রশ্ন একবার; গুদাম (সারি-দেখা-হতে-পারে নীতি) প্রতিবার; লেখার অনুরোধে কিছু মনে রাখা হয় না; চাবি গেলে পরের পাতায় নেই।
 */
final class TheProductListAsksEachKeyOnceAndAnswersTheSameTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        Product::query()->orderBy('id')->limit(1)->update(['is_active' => false]);

        $answer = function () {
            $user = auth()->user();
            // ⓘ চাবির তালিকা আর রোল আগে তোলা — আসল পাতায় মেনু তা আগেই করে
            $user->can('claim.warm');
            $user->can('claim.warm');
            $out = ['asked' => 0, 'same' => true, 'rows' => 0, 'may' => []];
            Gate::after(function ($u, string $ability, $result, array $arguments = []) use (&$out) {
                if (($arguments[0] ?? null) instanceof Product && ($out['counting'] ?? false)) {
                    $out['asked']++;
                }

                return null;
            });
            $rows = Product::query()->orderBy('id')->limit(12)->get();
            $out['counting'] = true;
            $listed = [];
            foreach ($rows as $row) {
                foreach (['update', 'delete'] as $ability) {
                    $listed[$row->id][$ability] = app(GateMemo::class)->ofTheList($user, $ability, $row);
                }
            }
            $out['counting'] = false;
            foreach ($rows as $row) {
                foreach (['update', 'delete'] as $ability) {
                    $out['same'] = $out['same'] && $listed[$row->id][$ability] === $user->can($ability, $row);
                }
            }

            return ['asked' => $out['asked'], 'same' => $out['same'], 'rows' => $rows->count(), 'may' => reset($listed)];
        };
        Route::middleware(['web', 'auth'])->match(['get', 'post'], '/claim-list-rights', $answer);
    }

    public function test_every_row_answers_as_the_gate_for_each_kind_of_person(): void
    {
        $people = [
            'owner' => [User::query()->where('email', 'owner@abos.test')->firstOrFail(), [true, true]],
            'accountant' => [$this->person('Claim accountant', ['inventory.product.view', 'inventory.product.update']), [true, false]],
            'sr' => [$this->person('Claim SR', ['inventory.product.view']), [false, false]],
            'stranger' => [$this->person('Claim nobody', []), [false, false]],
        ];

        foreach ($people as $who => [$user, $may]) {
            $got = $this->as($user)->get('/claim-list-rights')->assertOk()->json();
            $this->assertGreaterThan(1, $got['rows'], 'প্রস্তুতিটাই ভুল।');
            $this->assertTrue($got['same'], "⛔ {$who}: তালিকার উত্তর দরজার সমান নয়।");
            $this->assertSame(['update' => $may[0], 'delete' => $may[1]], $got['may'], $who);
            $this->assertSame(2, $got['asked'], "⛔ {$who}: দরজায় একবারের বেশি প্রশ্ন।");
        }
    }

    public function test_the_real_page_offers_each_person_what_their_keys_allow(): void
    {
        $product = Product::query()->where('is_active', true)->orderBy('name_en')->firstOrFail();

        foreach ([[['inventory.product.view', 'inventory.product.update'], true, false], [['inventory.product.view', 'inventory.product.delete'], false, true], [['inventory.product.view'], false, false]] as [$keys, $edit, $off]) {
            $html = (string) $this->as($this->person('P'.implode($keys), $keys))->get(route('inventory.product.index', ['q' => $product->code]))->assertOk()->getContent();
            $this->assertSame($edit, str_contains($html, route('inventory.product.edit', $product)), implode(',', $keys));
            $this->assertSame($off, str_contains($html, 'action="'.route('inventory.product.destroy', $product).'"'), implode(',', $keys));
        }
    }

    public function test_a_write_remembers_nothing_and_a_taken_key_is_gone_on_the_next_page(): void
    {
        $user = $this->person('Claim changing', ['inventory.product.view', 'inventory.product.update']);
        $post = $this->as($user)->post('/claim-list-rights')->assertOk()->json();
        $this->assertSame(2 * $post['rows'], $post['asked'], '⛔ লেখার অনুরোধে উত্তর মনে রাখা হলো।');

        $this->assertTrue($this->as($user)->get('/claim-list-rights')->json('may.update'));
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        User::query()->findOrFail($user->id)->roles()->firstOrFail()->revokePermissionTo('inventory.product.update');
        $this->assertFalse($this->as($user)->get('/claim-list-rights')->json('may.update'), '⛔ কেড়ে নেওয়া চাবি বাসি রইল।');
    }

    public function test_a_policy_that_did_not_declare_itself_is_asked_for_every_row(): void
    {
        Route::middleware(['web', 'auth'])->get('/claim-warehouse', function () {
            $asked = 0;
            Gate::after(function ($u, string $ability, $r, array $arguments = []) use (&$asked) {
                $asked += ($arguments[0] ?? null) instanceof Warehouse ? 1 : 0;

                return null;
            });
            $w = Warehouse::query()->firstOrFail();
            foreach ([1, 2, 3] as $_) {
                app(GateMemo::class)->ofTheList(auth()->user(), 'delete', $w);
            }

            return ['asked' => $asked];
        });

        $this->as(User::query()->where('email', 'owner@abos.test')->firstOrFail())->get('/claim-warehouse')->assertOk()->assertJson(['asked' => 3]);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        return $this->actingAs(User::query()->findOrFail($user->id));
    }

    /** @param  list<string>  $keys */
    private function person(string $name, array $keys): User
    {
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $role = Role::query()->create(['name' => 'claim-'.$user->id, 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $role->syncPermissions(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
