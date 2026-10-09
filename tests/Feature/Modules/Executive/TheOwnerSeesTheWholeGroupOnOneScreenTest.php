<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Figures;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * মালিকের কেন্দ্র, "আজ" — গোটা গ্রুপ এক পর্দায়, আর প্রতিটা ঘর ঐ কোম্পানির নিজের সংখ্যা।
 *
 * ── ⭐ সবচেয়ে বড় দাবিটা ──────────────────────────────────────────────
 * ছকের প্রতিটা ঘর = ঐ কোম্পানির ঐ শাখার ড্যাশবোর্ড যা দেখায়। ⓘ ড্যাশবোর্ডটা এখানে
 * খোলা হয় **আসল পথে** — মানুষটা সত্যিই কোম্পানি ও শাখা বদলান (হেডারের সুইচারের পথ),
 * আর মডিউলের ড্যাশবোর্ড ইঞ্জিন নিজের সংখ্যা দেয়। ⛔ মালিকের কেন্দ্র ভিতরে কোম্পানি
 * বদলায় স্মৃতিতে ([[CompanyLens::within()]]); দুই পথের সংখ্যা না মিললে এটা লাল।
 */
final class TheOwnerSeesTheWholeGroupOnOneScreenTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();

        // ⓘ দুই শাখায় বিক্রি, আর দ্বিতীয় কোম্পানিতে টাকা — যাতে প্রতিটা ঘর শূন্য না থাকে
        $this->actingAs($this->owner);
        $this->sellAt('WH-MMS', '3', '1500');
        $this->sellAt('WH-NTK', '2', '3600', 'Miniket Rice 50kg');

        CompanyContext::forCompany((int) $this->beta->id, function () {
            $this->putMoneyIn($this->moneyAccount(), '25000');
        });

        CompanyContext::set((int) $this->alpha->id, $this->owner->current_branch_id);
    }

    public function test_every_grid_cell_equals_that_companys_own_dashboard(): void
    {
        $checked = 0;

        foreach ([Figures::TODAY, Figures::MONTH] as $period) {
            $board = $this->board($this->owner, $period);

            foreach ($board['companies'] as $company) {
                $places = [['branch' => null, 'values' => $company['values']]];

                foreach ($company['rows'] as $row) {
                    $places[] = ['branch' => $row['id'], 'values' => $row['values']];
                }

                foreach ($places as $place) {
                    foreach ($this->dashboardStats($period) as $key => [$module, $label]) {
                        $cell = $place['values'][$key];

                        if ($cell === null) {
                            continue; // ⓘ শাখা ধরে ভাগ হয় না — ঘরে "—"
                        }

                        $shown = $this->dashboardFigure($company['id'], $place['branch'], $module, $label);
                        $expected = $key === Figures::SIGNATURES ? (string) (int) $cell : Money::format($cell);

                        $this->assertSame($shown, $expected, implode("\n", [
                            "⛔ {$company['name']}".($place['branch'] ? " / শাখা {$place['branch']}" : ' (সব শাখা)')." — '{$key}' ({$period})",
                            "মালিকের কেন্দ্র বলছে {$expected}, অথচ ঐ কোম্পানির নিজের ড্যাশবোর্ড বলছে {$shown}।",
                            'ⓘ দুই পর্দা দুই সংখ্যা বললে মালিক কোনটা বিশ্বাস করবেন জানতেন না।',
                        ]));

                        $checked++;
                    }
                }
            }
        }

        // ⓘ প্রস্তুতিটা কিছু মেপেছে কি না — শূন্য ঘর মিলিয়ে সবুজ হওয়া অর্থহীন
        $this->assertGreaterThan(40, $checked, 'প্রায় কোনো ঘরই মেলানো হয়নি — প্রস্তুতিটাই ভুল।');
        $board = $this->board($this->owner, Figures::TODAY);
        $ntk = $this->row($board, (int) $this->alpha->id, 'NTK');
        $this->assertSame(1, bccomp($ntk['values'][Figures::SALES], '0', 4), 'নেত্রকোনার বিক্রি শূন্য — প্রস্তুতিটাই ভুল।');
        $this->assertSame(1, bccomp($this->company($board, (int) $this->beta->id)['values'][Figures::FUND], '0', 4),
            'ফ্যামিলি মার্টের তহবিল শূন্য — দ্বিতীয় কোম্পানি আদৌ পড়া হয়নি।');
    }

    public function test_the_group_total_is_the_sum_of_the_rows(): void
    {
        $board = $this->board($this->owner, Figures::MONTH);

        foreach (Figures::KEYS as $key) {
            $sumOfCompanies = '0';

            foreach ($board['companies'] as $company) {
                $sumOfRows = '0';

                foreach ($company['rows'] as $row) {
                    $sumOfRows = bcadd($sumOfRows, $row['values'][$key] ?? '0', 4);
                }

                $sumOfRows = bcadd($sumOfRows, $company['unsplit'][$key] ?? '0', 4);

                $this->assertSame(0, bccomp($sumOfRows, $company['values'][$key], 4),
                    "⛔ {$company['name']}-এর '{$key}': সারিগুলোর যোগ {$sumOfRows}, অথচ মোট {$company['values'][$key]}।");

                $sumOfCompanies = bcadd($sumOfCompanies, $company['values'][$key], 4);
            }

            $this->assertSame(0, bccomp($sumOfCompanies, $board['total'][$key], 4),
                "⛔ গ্রুপের '{$key}': কোম্পানিগুলোর যোগ {$sumOfCompanies}, অথচ গ্রুপের মোট {$board['total'][$key]}।");
        }

        $this->assertSame(1, bccomp($board['total'][Figures::SALES], '0', 4), 'বিক্রির যোগ শূন্য — প্রস্তুতিটাই ভুল।');
    }

    public function test_nobody_sees_a_company_they_do_not_belong_to_not_even_its_name(): void
    {
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->grantTheKey($accountant, $this->alpha);

        // ⛔ ফ্যামিলি মার্টের সদস্যই নন — পাতার কোথাও নামটা নেই
        $this->actingAs($accountant->fresh())->get(route('executive.today'))
            ->assertOk()
            ->assertSee($this->alpha->name())
            ->assertDontSee($this->beta->name_bn)
            ->assertDontSee($this->beta->name_en);

        /*
         * ⚠️ সদস্য, কিন্তু সেখানে চাবি নেই — সেটাও "তাঁর নয়"। ⓘ হেডারের সুইচার সদস্যপদ দেখে নামটা
         * এমনিই দেখায়, তাই এখানে মাপা হয় মালিকের কেন্দ্রের নিজের অংশ: ছক আর ছাঁকনির তালিকা।
         */
        $accountant->companies()->attach($this->beta->id, ['is_active' => true]);

        $board = $this->board($accountant->fresh(), Figures::TODAY);
        $this->assertSame([(int) $this->alpha->id], array_column($board['companies'], 'id'), '⛔ চাবি ছাড়া কোম্পানি ছকে।');
        $this->assertSame([(int) $this->alpha->id], array_column($board['choices'], 'id'), '⛔ ছাঁকনির তালিকায় চাবি ছাড়া কোম্পানি।');

        $page = $this->actingAs($accountant->fresh())->get(route('executive.today'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-company="'.$this->beta->id.'"', $page, '⛔ চাবি ছাড়া কোম্পানির সারি পাতায়।');
        $this->assertStringNotContainsString('<option value="'.$this->beta->id.'"', $page, '⛔ চাবি ছাড়া কোম্পানি ছাঁকনিতে।');

        // ⛔ ঠিকানা হাতে লিখেও ঢোকা যায় না
        $this->actingAs($accountant->fresh())
            ->from(route('executive.today'))
            ->post(route('executive.open'), ['company' => $this->beta->id, 'route' => 'module.dashboard', 'params' => ['module' => 'sales']])
            ->assertSessionHasErrors('company');

        $this->assertSame((int) $this->alpha->id, (int) $accountant->fresh()->current_company_id,
            '⛔ চাবি ছাড়া কোম্পানিতে বদলে গেছে।');
    }

    public function test_without_the_key_the_screen_does_not_open(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($salesman)->get(route('executive.today'))->assertForbidden();
    }

    public function test_a_branch_limited_user_sees_only_their_branch(): void
    {
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->grantTheKey($accountant, $this->alpha);
        $mms = $this->branch('MMS');

        UserDataScope::query()->create([
            'company_id' => $this->alpha->id,
            'user_id' => $accountant->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $mms->id,
        ]);
        app(DataScope::class)->forget();

        $board = $this->board($accountant->fresh(), Figures::TODAY);
        $rows = $this->company($board, (int) $this->alpha->id)['rows'];

        $this->assertSame([(int) $mms->id], array_column($rows, 'id'), '⛔ সীমার বাইরের শাখা ছকে এসেছে।');

        /*
         * ⓘ আর কোম্পানির মোটে নেত্রকোনা ঢোকেনি: মোট = ঐ শাখা + শাখাহীন সারি — মালিকের ছকের
         * ময়মনসিংহ সারি আর "শাখায় ভাগ হয়নি" সারির যোগ, প্রতিটা দেখা সংখ্যায়।
         */
        $owners = $this->board($this->owner, Figures::TODAY);
        $alone = $this->row($owners, (int) $this->alpha->id, 'MMS');
        $unsplit = $this->company($owners, (int) $this->alpha->id)['unsplit'] ?? [];
        $mine = $this->company($board, (int) $this->alpha->id)['values'];
        $compared = 0;

        foreach (Figures::KEYS as $key) {
            if ($mine[$key] === Figures::HIDDEN || ! Figures::definition($key)['byBranch']) {
                continue;
            }

            $this->assertSame(0, bccomp(bcadd($alone['values'][$key], $unsplit[$key] ?? '0', 4), $mine[$key], 4),
                "⛔ শাখা-বাঁধা মানুষের '{$key}'-এ অন্য শাখার সংখ্যা ঢুকেছে।");
            $compared++;
        }

        $this->assertGreaterThan(0, $compared, 'প্রস্তুতিটাই ভুল — হিসাবরক্ষক কোনো সংখ্যাই দেখেন না।');
        $this->assertSame(1, bccomp($owners['companies'][0]['values'][Figures::RECEIVABLE], '0', 4), 'প্রস্তুতিটাই ভুল — পাওনা শূন্য।');
        $this->assertSame(1, bccomp($this->row($owners, (int) $this->alpha->id, 'NTK')['values'][Figures::RECEIVABLE], '0', 4),
            'প্রস্তুতিটাই ভুল — নেত্রকোনার পাওনা শূন্য, তাই "ঢোকেনি" কিছুই প্রমাণ করে না।');

        $this->actingAs($accountant->fresh())->get(route('executive.today'))
            ->assertOk()
            ->assertDontSee($this->branch('NTK')->name());
    }

    public function test_the_header_branch_narrows_the_page(): void
    {
        $ntk = $this->branch('NTK');
        $everything = $this->board($this->owner, Figures::TODAY);

        $this->owner->switchCompany((int) $this->alpha->id, (int) $ntk->id);
        $this->owner->forceFill(['view_all_branches' => false])->save();

        $board = $this->board($this->owner->fresh(), Figures::TODAY);
        $alpha = $this->company($board, (int) $this->alpha->id);

        $this->assertSame([(int) $ntk->id], array_column($alpha['rows'], 'id'), '⛔ হেডারে নেত্রকোনা বাছা, অথচ ছকে অন্য শাখাও।');
        $this->assertSame($this->row($everything, (int) $this->alpha->id, 'NTK')['values'][Figures::SALES], $alpha['values'][Figures::SALES],
            '⛔ হেডারের শাখা বাছার পর কোম্পানির মোট ঐ শাখার বিক্রি নয়।');

        // ⓘ হেডারের শাখা একটা কোম্পানির — অন্য কোম্পানি তাতে বদলায় না
        $this->assertSame(
            $this->company($everything, (int) $this->beta->id)['values'][Figures::FUND],
            $this->company($board, (int) $this->beta->id)['values'][Figures::FUND],
        );
    }

    public function test_a_cell_opens_that_company_and_branch(): void
    {
        $ntk = $this->branch('NTK');

        $this->actingAs($this->owner)
            ->post(route('executive.open'), ['go' => http_build_query([
                'company' => $this->beta->id, 'route' => 'module.dashboard', 'params' => ['module' => 'finance'],
            ])])
            ->assertRedirect(route('module.dashboard', ['module' => 'finance']));

        $this->assertSame((int) $this->beta->id, (int) $this->owner->fresh()->current_company_id, '⛔ কোম্পানি বদলায়নি।');
        $this->assertTrue((bool) $this->owner->fresh()->view_all_branches, 'শাখা না বাছলে সব শাখা দেখার কথা।');

        $this->actingAs($this->owner->fresh())
            ->post(route('executive.open'), ['company' => $this->alpha->id, 'branch' => $ntk->id, 'route' => 'module.dashboard', 'params' => ['module' => 'sales']])
            ->assertRedirect(route('module.dashboard', ['module' => 'sales']));

        $this->assertSame((int) $ntk->id, (int) $this->owner->fresh()->current_branch_id, '⛔ শাখা বদলায়নি।');
        $this->assertFalse((bool) $this->owner->fresh()->view_all_branches, '⛔ শাখা বাছার পরও "সব শাখা" দেখছে।');

        // ⛔ বাইরের ঠিকানায় পাঠানো যায় না — কেবল এই অ্যাপের পড়ার পাতা
        $this->actingAs($this->owner->fresh())
            ->from(route('executive.today'))
            ->post(route('executive.open'), ['company' => $this->alpha->id, 'route' => 'executive.refresh'])
            ->assertSessionHasErrors('route');
    }

    public function test_the_page_opens_and_every_cell_is_clickable(): void
    {
        $page = $this->actingAs($this->owner)->get(route('executive.today', ['period' => 'month']));

        $page->assertOk()
            ->assertSee($this->alpha->name())
            ->assertSee($this->beta->name())
            ->assertSee(__('executive::today.group_total'));

        // ⓘ প্রতিটা দেখা সংখ্যার ঘর একটা বোতাম — উৎসে নামার পথ
        $this->assertGreaterThan(20, substr_count($page->getContent(), 'form="executive-open"'));
    }

    public function test_figures_are_cached_until_refresh_now(): void
    {
        $before = $this->board($this->owner, Figures::TODAY)['total'][Figures::SALES];

        $this->sellAt('WH-MMS', '1', '700');
        $this->assertSame($before, $this->board($this->owner, Figures::TODAY)['total'][Figures::SALES],
            'ক্যাশ কাজ করছে না — প্রতিবার নতুন করে গোনা হচ্ছে।');

        $this->actingAs($this->owner)->from(route('executive.today'))->post(route('executive.refresh'))->assertRedirect();

        $this->assertSame(0, bccomp(bcadd($before, '700', 4), $this->board($this->owner, Figures::TODAY)['total'][Figures::SALES], 4),
            '⛔ "এখনই নতুন করে" চাপার পরও পুরনো সংখ্যা।');
    }

    // ── সাহায্য ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function board(User $user, string $period): array
    {
        $this->actingAs($user);
        CompanyContext::set((int) $user->current_company_id, $user->current_branch_id === null ? null : (int) $user->current_branch_id);

        $board = app(Board::class)->build($user, $period);

        $this->assertSame((int) $user->current_company_id, CompanyContext::id(), '⛔ ছক গোনার পর প্রসঙ্গটা ফেরেনি।');

        return $board;
    }

    /**
     * আটটা সংখ্যা কোন ড্যাশবোর্ডের কোন ঘরে।
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function dashboardStats(string $period): array
    {
        $out = [
            Figures::RECEIVABLE => ['accounts', __('accounts::dashboard.receivable')],
            Figures::PAYABLE => ['accounts', __('accounts::dashboard.payable')],
            Figures::FUND => ['finance', __('finance::dashboard.total_fund')],
            Figures::STOCK => ['inventory', __('inventory::overview.stock_value')],
            Figures::SIGNATURES => ['approval', __('approval::dashboard.pending')],
        ];

        return $period === Figures::TODAY
            ? [...$out, Figures::SALES => ['sales', __('sales::dashboard.sales_today')], Figures::COLLECTIONS => ['sales', __('sales::dashboard.collected_today')]]
            : [...$out, Figures::SALES => ['sales', __('sales::dashboard.sales_this_month')], Figures::PROFIT => ['accounts', __('accounts::dashboard.net_profit_month')]];
    }

    /**
     * ঐ কোম্পানির ঐ শাখার ড্যাশবোর্ডের ঘর — আসল পথে: হেডারের সুইচার দিয়ে কোম্পানি আর শাখা বদলে।
     */
    private function dashboardFigure(int $companyId, ?int $branchId, string $module, string $label): ?string
    {
        $user = $this->owner->fresh();
        $user->switchCompany($companyId, $branchId);
        $user->forceFill(['view_all_branches' => $branchId === null])->save();
        $user = $user->fresh();

        CompanyContext::set($companyId, (int) $user->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($user);

        try {
            foreach (app(DashboardEngine::class)->for($module, $user)->stats as $stat) {
                /** @var Stat $stat */
                if ($stat->label === $label) {
                    return $stat->value;
                }
            }
        } finally {
            $this->owner->switchCompany((int) $this->alpha->id);
            $this->owner->forceFill(['view_all_branches' => true])->save();
            $this->owner = $this->owner->fresh();
            CompanyContext::set((int) $this->alpha->id, (int) $this->owner->current_branch_id);
            app(DataScope::class)->forget();
            $this->actingAs($this->owner);
        }

        $this->fail("'{$module}' ড্যাশবোর্ডে '{$label}' ঘরটা নেই — নাম বদলেছে?");
    }

    private function sellAt(string $warehouseCode, string $qty, string $rate, string $product = 'Cosmos Biscuit 40gm'): void
    {
        CompanyContext::forCompany((int) $this->alpha->id, function () use ($warehouseCode, $qty, $rate, $product) {
            $warehouse = Warehouse::query()->where('code', $warehouseCode)->firstOrFail();
            CompanyContext::set((int) $this->alpha->id, (int) $warehouse->branch_id);

            $result = app(DirectSaleService::class)->complete(
                ['customer_id' => Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
                    'warehouse_id' => $warehouse->id, 'own_transport' => '1', DirectSaleService::REPEAT_FIELD => '1'],
                [['product_id' => Product::query()->where('name_en', $product)->firstOrFail()->id,
                    'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
            );

            $this->assertContains($result['invoice']->fresh()->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিক্রি পাকা হয়নি।');
            $this->assertSame((int) $warehouse->branch_id, (int) $result['invoice']->branch_id, 'প্রস্তুতিটাই ভুল — বিল অন্য শাখায় বসেছে।');
        });
    }

    private function moneyAccount(): Account
    {
        return Account::query()->whereIn('money_kind', Account::MONEY_KINDS)->where('is_group', false)->orderBy('code')->firstOrFail();
    }

    private function grantTheKey(User $user, Company $company): void
    {
        CompanyContext::forCompany((int) $company->id, function () use ($user) {
            foreach ($user->roles as $role) {
                Role::findById($role->id)->givePermissionTo('executive.view');
            }
        });
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function branch(string $code): Branch
    {
        return Branch::acrossAllCompanies()->where('company_id', $this->alpha->id)->where('code', $code)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function company(array $board, int $id): array
    {
        foreach ($board['companies'] as $company) {
            if ($company['id'] === $id) {
                return $company;
            }
        }

        $this->fail("কোম্পানি {$id} ছকে নেই।");
    }

    /** @return array<string, mixed> */
    private function row(array $board, int $companyId, string $branchCode): array
    {
        $id = (int) $this->branch($branchCode)->id;

        foreach ($this->company($board, $companyId)['rows'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        $this->fail("শাখা {$branchCode} ছকে নেই।");
    }
}
