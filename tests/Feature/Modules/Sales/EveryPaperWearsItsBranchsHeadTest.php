<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PrintsInItsBranch;
use App\Core\Services\BranchSettings;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Http\Controllers\MoneyTransferPrintController;
use App\Modules\Hr\Http\Controllers\PayslipPrintController;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Inventory\Http\Controllers\StockPrintController;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Purchase\Http\Controllers\PurchasePrintController;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use ReflectionMethod;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⛔ ক্রয়, মজুদ-বদলি, টাকা-বদলি আর বেতনশিট — নিজের শাখার মাথা আর লোগোয় ছাপা (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৮;
 * [[PrintsInItsBranch]])।
 *
 * ⓘ বিক্রয়ের কাগজ শাখার সেটিংয়ে আঁকা হত ([[BranchSettings::during()]]), এই চারটা হত না — নেত্রকোনার ক্রয় আদেশেও কোম্পানির মাথা।
 * ছাঁচ আঁকার মুহূর্তে কোন শাখার সেটিং চালু, সেটাই মাপা।
 */
final class EveryPaperWearsItsBranchsHeadTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->far = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->whereKeyNot($company->defaultBranch()->id)->orderBy('id')->firstOrFail();
    }

    public function test_a_purchase_order_and_a_stock_transfer_print_under_their_own_branch(): void
    {
        $order = app(PurchaseOrderService::class)->create(['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'ordered_qty' => '10', 'rate' => '100']]);
        DB::table('pur_orders')->where('id', $order->id)->update(['branch_id' => $this->far->id]);
        $this->assertSame($this->far->id, $this->branchWhilePrinting(route('purchase.print.order', $order)), '⛔ ক্রয় আদেশ নিজের শাখার মাথায় ছাপা হয়নি');

        $from = Warehouse::query()->where('is_default', true)->firstOrFail();
        $to = Warehouse::query()->whereKeyNot($from->id)->first() ?? Warehouse::query()->create(['company_id' => CompanyContext::id(),
            'code' => 'WH2', 'name_en' => 'Second store', 'name_bn' => 'দ্বিতীয় গুদাম', 'is_default' => false]);
        $transfer = app(StockTransferService::class)->create(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '5']]);
        DB::table('inv_transfers')->where('id', $transfer->id)->update(['branch_id' => $this->far->id]);
        $this->assertSame($this->far->id, $this->branchWhilePrinting(route('inventory.transfer.print', $transfer)), '⛔ মজুদ-বদলি নিজের শাখার মাথায় ছাপা হয়নি');
    }

    public function test_the_money_transfer_and_payslip_prints_know_their_branch(): void
    {
        foreach ([PurchasePrintController::class, StockPrintController::class, MoneyTransferPrintController::class, PayslipPrintController::class] as $class) {
            $this->assertContains(PrintsInItsBranch::class, class_uses($class), "⛔ {$class} শাখার মাথায় ছাপে না");
        }

        // ⓘ বেতনশিটের নিজের শাখা নেই — কর্মীর শাখা
        $employee = Employee::query()->create(['company_id' => CompanyContext::id(), 'branch_id' => $this->far->id, 'code' => 'E-HEAD',
            'name_en' => 'Head Person', 'joining_date' => '2026-01-01']);
        $slip = (new Payslip)->forceFill(['employee_id' => $employee->id]);
        $this->assertSame($this->far->id, (new ReflectionMethod(PayslipPrintController::class, 'branchOfPaper'))
            ->invoke(app(PayslipPrintController::class), $slip));
    }

    private function branchWhilePrinting(string $url): ?int
    {
        $seen = 'never';
        View::composer('print.*', function () use (&$seen) {
            if ($seen === 'never') {
                $seen = app(BranchSettings::class)->printingBranch();
            }
        });

        $this->get($url)->assertOk();
        $this->assertNotSame('never', $seen, 'ছাঁচ ডাকাই হয়নি');

        return $seen;
    }
}
