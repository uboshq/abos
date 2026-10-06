<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Core\Services\DealerScope;
use App\Core\Services\RoleTemplateRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DealerOwnership;
use App\Modules\Sales\Services\SalesTargetService;
use App\Core\Engines\Sync\SyncService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মালিকের চার উত্তর, ৩ অক্টোবর ২০২৬ ("ক ক খ ক") — ⛔১৬-এর দ্বিতীয় ধাপ।
 *
 *  ক · চলতি মাঠের রোল (SR, TSM, ASM …) দেয়ালের চিহ্ন নিজে থেকে পায়; অফিসের রোল কখনো নয়।
 *  ক · লক্ষ্যমাত্রা আর কমিশন যায় **বিলের দিনে** যিনি ডিলারে বাঁধা ছিলেন তাঁর ঘরে।
 *  খ · বিক্রয়কর্মী ফোন থেকে টাকা নেন না — "টাকা নেবে কেবল অফিস"; আগের খসড়া আদায় অফিস মেটায়।
 *  ক · ডেমোর বিক্রয়কর্মী দেয়ালের ভিতরে, একজন ডিলার বাঁধনহীন।
 *
 * ⓘ দাবিগুলো একই মানুষ দুইবার, যেখানে তুলনা আছে ([[a-door-claim-needs-one-actor-twice]])।
 */
final class TheSaleBelongsToTheSalesmanBoundThatDayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->forget();
    }

    // ── ক · চলতি রোলে চিহ্ন ────────────────────────────────────────────────

    /** ⭐ স্থানান্তর কেবল মাঠের রোলে চিহ্ন দেয় — SR, TSM, ASM পান; ম্যানেজার, হিসাবরক্ষক, কাউন্টার, সুপার অ্যাডমিন আর "সব ডিলার"-ওয়ালা নন। */
    public function test_the_migration_marks_only_the_field_roles(): void
    {
        $roles = CompanyContext::forCompany($this->company->id, function (): array {
            $order = Permission::findOrCreate('sales.order.create', 'web');
            $made = [];

            foreach (['SR', 'TSM', 'ASM', 'DSM', 'Manager', 'Accountant', 'Counter', 'super_admin'] as $name) {
                $role = Role::findOrCreate($name, 'web');
                $role->givePermissionTo($order);
                $role->revokePermissionTo(DealerScope::OWN);
                $made[$name] = $role;
            }

            // ⓘ একটা মাঠের রোল যার "সব ডিলার" চাবি আছে — চিহ্ন পাবে না
            $made['DSM']->givePermissionTo(Permission::findOrCreate(DealerScope::ALL, 'web'));

            return $made;
        });
        $this->forget();

        $migration = require base_path('app/Modules/Customer/Database/Migrations/2027_01_31_210000_the_field_roles_were_never_told_they_see_only_their_dealers.php');
        $migration->up();
        $this->forget();

        foreach (['SR', 'TSM', 'ASM'] as $name) {
            $this->assertTrue($roles[$name]->fresh()->hasPermissionTo(DealerScope::OWN), "⛔ মাঠের রোল {$name} চিহ্ন পায়নি।");
        }

        foreach (['DSM', 'Manager', 'Accountant', 'Counter'] as $name) {
            $this->assertFalse($roles[$name]->fresh()->permissions->contains('name', DealerScope::OWN), "⛔ {$name} চিহ্ন পেয়েছে।");
        }

        $this->assertFalse(app(DealerScope::class)->walled($this->owner->fresh()), '⛔ মালিক দেয়ালে আটকেছেন।');
    }

    /** ⓘ ছাঁচে যে রোল চিহ্ন পায়, তার নাম স্থানান্তরের তালিকাতেও আছে — দুই জায়গা আলাদা হয়ে না যায়। */
    public function test_every_template_role_that_carries_the_mark_is_a_field_role(): void
    {
        $carrying = [];

        foreach (app(RoleTemplateRegistry::class)->all() as $role => $keys) {
            if (in_array(DealerScope::OWN, $keys, true)) {
                $carrying[] = $role;
            }
        }

        $this->assertNotEmpty($carrying, 'কোনো ছাঁচেই চিহ্ন নেই — দাবিটা কিছু মাপছে না।');
        $this->assertSame([], array_values(array_diff($carrying, DealerScope::FIELD_ROLES)));
        $this->assertSame([], array_values(array_intersect($carrying, DealerScope::NEVER_MARKED)));
    }

    // ── ক · কার বিক্রি ──────────────────────────────────────────────────────

    /**
     * ⭐ হাতবদলের আগের দিনের বিক্রি পুরনো জনের, হাতবদলের দিনের বিক্রি নতুন জনের — বিল যিনি
     * কেটেছেন (মালিক) কারো নন; বাঁধনহীন ডিলারের বিক্রি কারো ঘরে নয়।
     */
    public function test_a_sale_is_credited_to_whoever_was_bound_on_its_date(): void
    {
        $this->actingAs($this->owner);
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $niloy = Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail();
        $new = $this->colleague('new-sr@abos.test');

        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();

        app(DealerBindingService::class)->handover((int) $this->sales->id, (int) $new->id, [(int) $rahim->id], $today->toDateString());

        $this->bill('CR-OLD', $rahim, $yesterday, '300.0000');
        $this->bill('CR-NEW', $rahim, $today, '500.0000');
        $this->bill('CR-LOOSE', $niloy, $today, '900.0000');

        $who = app(DealerOwnership::class);
        $this->assertSame((int) $this->sales->id, $who->srOn($rahim, $yesterday));
        $this->assertSame((int) $new->id, $who->srOn($rahim, $today));
        $this->assertNull($who->srOn($niloy, $today), 'বাঁধনহীন ডিলারের বিক্রি কারো নয়।');

        $achieved = app(SalesTargetService::class)->achievedByUser($yesterday, $today);

        $this->assertSame(0, bccomp((string) ($achieved[$this->sales->id] ?? '0'), '300', 4), '⛔ পুরনো জন হাতবদলের আগের বিক্রি পাননি।');
        $this->assertSame(0, bccomp((string) ($achieved[$new->id] ?? '0'), '500', 4), '⛔ নতুন জন হাতবদলের পরের বিক্রি পাননি।');
        $this->assertArrayNotHasKey((int) $this->owner->id, $achieved, '⛔ বিল যিনি কেটেছেন তিনি অর্জন পেয়েছেন।');
        $this->assertSame(0, bccomp(array_reduce($achieved, fn ($s, $v) => bcadd($s, (string) $v, 4), '0'), '800', 4),
            '⛔ বাঁধনহীন ডিলারের বিক্রি কারো ঘরে উঠেছে।');
    }

    // ── খ · ফোন থেকে টাকা নয় ────────────────────────────────────────────────

    /**
     * ⛔ একই বিক্রয়কর্মী: চিহ্ন ছাড়া ফোনের আদায় খসড়া হয়; চিহ্নসহ "টাকা নেবে কেবল অফিস"। ⭐ আগের খসড়া
     * বৈধই থাকে, অফিস সেটা নিশ্চিত করে।
     */
    public function test_a_walled_salesman_cannot_send_money_from_the_phone_but_old_drafts_still_settle(): void
    {
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->mark(false);
        $this->actingAs($this->sales->fresh());

        $out = app(SyncService::class)->push($this->sales->fresh(), 'phone-dw', 'sales', [$this->collection('dw-c-1', $rahim, '700')]);
        $this->assertSame(SyncChange::APPLIED, $out[0]['status'], 'চিহ্ন ছাড়াও ফোনের আদায় বসেনি — দাবিটা কিছু মাপছে না।');
        $old = Collection::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->mark(true);
        $this->actingAs($this->sales->fresh());
        $before = Collection::query()->withoutGlobalScopes()->count();

        $out = app(SyncService::class)->push($this->sales->fresh(), 'phone-dw', 'sales', [$this->collection('dw-c-2', $rahim, '400')]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status']);
        $this->assertSame((string) __('customer::binding.office_takes_money'), $out[0]['message'] ?? null);
        $this->assertStringContainsString('টাকা নেবে কেবল অফিস', (string) __('customer::binding.office_takes_money', [], 'bn'));
        $this->assertSame($before, Collection::query()->withoutGlobalScopes()->count(), '⛔ প্রত্যাখ্যাত আদায়ও খাতায় বসেছে।');

        $this->actingAs($this->owner->fresh());
        $this->forget();
        $settled = app(CollectionService::class)->confirm(Collection::query()->findOrFail($old->id));
        $this->assertSame(DocumentStatus::CONFIRMED, $settled->status, '⛔ ফোনের পুরনো খসড়া অফিস মেটাতে পারল না।');
    }

    // ── ক · ডেমো ────────────────────────────────────────────────────────────

    /** ⭐ ডেমোর বিক্রয়কর্মী দেয়ালের ভিতরে: নিজের ডিলার দেখেন, "Niloy Store" দেখেন না; মালিক দেখেন। */
    public function test_the_demo_salesman_is_walled_and_one_dealer_is_left_unbound(): void
    {
        $this->assertTrue(app(DealerScope::class)->walled($this->sales->fresh()), '⛔ ডেমোর বিক্রয়কর্মী দেয়ালে নেই।');
        $this->assertSame(0, DealerBinding::query()->withoutGlobalScopes()
            ->whereIn('customer_id', Customer::acrossDealers()->where('name_en', 'Niloy Store')->pluck('id'))->count());

        $this->sales->forceFill(['locale' => 'en'])->save();
        $this->owner->forceFill(['locale' => 'en'])->save();

        $this->actingAs($this->sales->fresh())->get(route('customer.index'))->assertOk()
            ->assertSee('Rahim Traders')->assertDontSee('Niloy Store');

        $this->app['auth']->forgetGuards();
        $this->forget();
        $this->actingAs($this->owner->fresh())->get(route('customer.index'))->assertOk()
            ->assertSee('Rahim Traders')->assertSee('Niloy Store');
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────────

    private function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();
        app(\App\Core\Services\PermissionOverrides::class)->forget();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function mark(bool $on): void
    {
        CompanyContext::forCompany($this->company->id, function () use ($on): void {
            $role = Role::findByName('salesman', 'web');
            $on ? $role->givePermissionTo(Permission::findOrCreate(DealerScope::OWN, 'web')) : $role->revokePermissionTo(DealerScope::OWN);
        });
        $this->forget();
    }

    private function colleague(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->assignRole('salesman'));
        $this->forget();

        return $user;
    }

    private function bill(string $no, Customer $customer, Carbon $on, string $amount): void
    {
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => $customer->id,
            'trx_date' => $on->toDateString(),
            'subtotal' => $amount,
            'total' => $amount,
            'status' => DocumentStatus::CONFIRMED,
            'created_by' => $this->owner->id,
        ]);

        (new SalesInvoiceLine)->forceFill([
            'line_no' => 1,
            'sales_invoice_id' => $invoice->id,
            'product_id' => Product::query()->firstOrFail()->id,
            'qty' => '1',
            'rate' => $amount,
            'amount' => $amount,
            'tax' => '0',
        ])->save();
    }

    /** @return array<string, mixed> */
    private function collection(string $id, Customer $customer, string $amount): array
    {
        return [
            'changeId' => $id,
            'entityType' => 'Collection',
            'operation' => 'CREATE',
            'payloadJson' => json_encode(['customerId' => (string) $customer->public_id, 'amount' => $amount]),
        ];
    }
}
