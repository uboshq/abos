<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Services\PurchaseRequisitionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Nobody asked whether the department had the money.
 *
 * A requisition could name a department, but only as free text - a label.
 * Budget rows are keyed to a cost centre, so the two halves never met and the
 * question "what has this department left this month" could not be asked.
 *
 * What this file guards:
 *   1. Without a cost centre nothing changes - no budget is looked for
 *   2. Without a budget row nothing changes either
 *   3. Within the budget the paper is approved, and the edge itself passes
 *   4. Past it the approval is refused
 *   5. Already-approved requisitions count against the same month
 *   6. A cancelled one does not
 *
 * (1) and (2) matter most: they are what makes the limit opt-in. Without them,
 * adding this feature would have stopped every depot that never set a budget -
 * a new capability paid for by everybody else's work.
 *
 * (5) is easiest to get wrong. Counting bills instead of approved requisitions
 * would make the limit act too late, when ten papers are through and the
 * budget is three times over.
 */
final class NobodyAskedWhetherTheDepartmentHadTheMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private CostCenter $centre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->product = Product::query()->orderBy('id')->firstOrFail();

        $this->centre = CostCenter::query()->create([
            'code' => 'CC-BUD',
            'name_en' => 'Budget test centre',
            'name_bn' => 'Budget test centre',
            'is_active' => true,
        ]);
    }

    // -- 1 and 2 - the limit is opt-in --------------------------------

    public function test_a_requisition_without_a_cost_centre_is_approved_as_before(): void
    {
        /*
         * A depot that does not use budgets must not notice this feature at
         * all. If it did, the cost of adding it would fall on everybody who
         * never asked for it.
         */
        $this->aBudgetOf('100');

        $paper = $this->aRequisition('10', '50', null);

        $this->service()->approve($paper);

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->fresh()->status,
            'A paper with no cost centre was stopped by a budget it never '
            .'claimed to belong to.');
    }

    public function test_a_cost_centre_with_no_budget_stops_nothing(): void
    {
        /*
         * The centre is named but nobody has written a number for the month.
         * Refusing here would mean that creating a cost centre silently became
         * a spending freeze.
         */
        $paper = $this->aRequisition('10', '50', $this->centre);

        $this->service()->approve($paper);

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->fresh()->status,
            'A centre with no budget row refused a requisition, so setting up '
            .'a cost centre would freeze spending by itself.');
    }

    // -- 3 and 4 - the wall ------------------------------------------

    public function test_within_the_budget_the_paper_goes_through(): void
    {
        $this->aBudgetOf('1000');

        $paper = $this->aRequisition('10', '50', $this->centre);

        $this->service()->approve($paper);

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->fresh()->status);
    }

    public function test_the_edge_itself_is_allowed(): void
    {
        /*
         * A budget of 500 and a request of exactly 500 must pass. Testing only
         * with a wildly larger number would pass even if the comparison read
         * "at or past" instead of "past".
         */
        $this->aBudgetOf('500');

        $paper = $this->aRequisition('10', '50', $this->centre);

        $this->service()->approve($paper);

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->fresh()->status,
            'A request that fills the budget exactly was refused - the limit '
            .'is reading "past it" as "up to it".');
    }

    public function test_past_the_budget_the_approval_is_refused(): void
    {
        $this->aBudgetOf('500');

        $this->service()->approve($this->aRequisition('10', '50', $this->centre));

        $tooMuch = $this->aRequisition('1', '0.01', $this->centre);

        $this->expectException(ValidationException::class);

        $this->service()->approve($tooMuch);
    }

    // -- 5 and 6 - what counts as spent ------------------------------

    public function test_an_approved_requisition_counts_against_the_month(): void
    {
        /*
         * This is the whole point. Counting bills instead would let ten papers
         * through and only bite on the day the bills arrived.
         */
        $this->aBudgetOf('500');

        $this->service()->approve($this->aRequisition('5', '50', $this->centre));

        $this->expectException(ValidationException::class);

        $this->service()->approve($this->aRequisition('6', '50', $this->centre));
    }

    public function test_a_cancelled_requisition_holds_no_money(): void
    {
        /*
         * A promise withdrawn is not a commitment. If cancelled papers counted,
         * a department could be frozen for a month by a mistake somebody had
         * already corrected.
         */
        $this->aBudgetOf('500');

        $first = $this->aRequisition('9', '50', $this->centre);
        $this->service()->approve($first);
        $this->service()->cancel($first->fresh(), 'ordered elsewhere');

        $second = $this->aRequisition('9', '50', $this->centre);

        $this->service()->approve($second);

        $this->assertSame(DocumentStatus::CONFIRMED, $second->fresh()->status,
            'A cancelled requisition is still holding budget, so a corrected '
            .'mistake freezes the department for the rest of the month.');
    }

    // -- helpers -----------------------------------------------------

    private function service(): PurchaseRequisitionService
    {
        return app(PurchaseRequisitionService::class);
    }

    private function aBudgetOf(string $amount): void
    {
        DB::table('fin_budgets')->insert([
            'company_id' => CompanyContext::id(),
            'year' => (int) now()->format('Y'),
            'month' => (int) now()->format('n'),

            /*
             * Any account will do: the limit sums a centre's rows across every
             * account, because a requisition names no account and a purchase
             * order buys an asset the budget does not know about.
             */
            'account_id' => Account::query()->firstOrFail()->id,
            'cost_center_id' => $this->centre->id,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function aRequisition(string $qty, string $rate, ?CostCenter $centre): PurchaseRequisition
    {
        return $this->service()->create(
            [
                'trx_date' => now()->toDateString(),
                'cost_center_id' => $centre?->id,
            ],
            [['product_id' => $this->product->id, 'qty' => $qty, 'estimated_rate' => $rate]],
        );
    }
}
