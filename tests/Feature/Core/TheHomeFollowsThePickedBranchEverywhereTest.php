<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\HomePeriod;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হোমের প্রতিটা সূচক আর প্রতিটা চার্ট হেডারে বাছা শাখা মানে — মালিক, ৫ অক্টোবর ২০২৬:
 * *"ekta company r ekta branch select kora but dekhacche sob branche hisab"*।
 *
 * ⓘ ধরা পড়েছিল: হিসাবের "এই মাস এ পর্যন্ত" (আয়-ব্যয়) চার্ট শাখা মানত না; বাকি সব মানত। [[TheDashboardShowsTheBranchYouPickedTest]]
 * কেবল পুরনো কার্ড মাপত, চার্ট নয় — তাই ফাঁকটা চোখে পড়েনি।
 * ⓘ দাবি, একই মালিক: শাখা A বাছা → শাখা B-তে বিক্রি আর ব্যাংক জমা → হোমের কোনো সূচক বা চার্ট বদলায় না;
 * "সব শাখা" বাছা → বিক্রি আর হিসাবের চার্ট বদলায় (দাবিটা সত্যিই তাকায়)।
 */
final class TheHomeFollowsThePickedBranchEverywhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_on_the_home_moves_for_another_branch_and_all_branches_sees_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $owner = $this->pick($company, $owner, (string) $a->id);
        $beforeA = $this->home($owner);
        $owner = $this->pick($company, $owner, 'all');
        $beforeAll = $this->home($owner);

        // ── শাখা B-তে বিক্রি আর ব্যাংক জমা ──
        CompanyContext::set($company->id, $b->id);
        $sales = app(SalesInvoiceService::class);
        $invoice = $sales->create(
            ['customer_id' => Customer::query()->orderBy('id')->value('id'), 'branch_id' => $b->id, 'trx_date' => now()->toDateString(),
                'warehouse_id' => Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('branch_id', $b->id)->orderBy('id')->value('id')],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '7777']],
        );
        $sales->confirm($invoice);
        $bank = app(AccountService::class)->create(['name_en' => 'Branch B bank', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);
        app(PostingEngine::class)->post(sourceType: 'test:home-branch', sourceId: 1, trxDate: now(),
            lines: [['account_id' => $bank->id, 'debit' => '5555'], ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '5555']],
            branchId: $b->id);
        $this->assertSame($b->id, (int) $invoice->fresh()->branch_id, 'বিলটা শাখা B-তে বসেনি — দাবির ভিত নেই।');

        // ── A বাছা: কিছুই নড়ে না ──
        $owner = $this->pick($company, $owner, (string) $a->id);
        $afterA = $this->home($owner);
        foreach ($beforeA as $what => $was) {
            $this->assertSame($was, $afterA[$what] ?? null, "⛔ শাখা A বাছা, অথচ শাখা B-র লেনদেনে হোমের \"{$what}\" বদলেছে।");
        }

        // ── সব শাখা: বিক্রি আর হিসাবের চার্ট নড়ে — দাবিটা সত্যিই তাকায় ──
        $owner = $this->pick($company, $owner, 'all');
        $afterAll = $this->home($owner);
        foreach (['chart:sales', 'chart:accounts'] as $what) {
            $this->assertArrayHasKey($what, $afterAll, "হোমে {$what} নেই — দাবি কিছু দেখবে না।");
            $this->assertNotSame($beforeAll[$what], $afterAll[$what], "\"সব শাখা\"-তেও {$what} বদলায়নি — দাবিটা অন্ধ।");
        }
    }

    private function pick(Company $company, User $owner, string $branch): User
    {
        $this->actingAs($owner)->post(route('branch.switch'), ['branch_id' => $branch])->assertRedirect();
        $owner = $owner->fresh();
        CompanyContext::set($company->id, $owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($owner);

        return $owner;
    }

    /**
     * হোম যা দেখায় — মূল সূচক আর ৮টা চার্টের প্রতিটা (মডিউলের প্রথম চার্ট), হোমের সময়ে আঁকা।
     *
     * @return array<string, string>
     */
    private function home(User $owner): array
    {
        app(DataScope::class)->forget();

        return HomePeriod::during('month', function () use ($owner) {
            $out = [];
            foreach (app(DashboardRegistry::class)->forUser($owner)['kpi'] ?? [] as $widget) {
                $out['kpi:'.$widget->label] = $widget->value;
            }
            foreach (app(DashboardEngine::class)->overall($owner) as $row) {
                if (in_array($row['module'], config('abos.home_pictures'), true) && $row['panel'] !== null) {
                    $out['chart:'.$row['module']] = md5(json_encode($row['panel']));
                }
            }

            return $out;
        });
    }
}
