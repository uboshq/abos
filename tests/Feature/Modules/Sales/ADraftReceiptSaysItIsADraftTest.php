<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\CollectionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use ReflectionMethod;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * খসড়া টাকার রসিদ নিজেকে খসড়া বলে — অডিট, ৬ অক্টোবর ২০২৬ (সমন্বয়ক): আগে খসড়া আদায়ের রসিদ হুবহু পাকা রসিদের মতো
 * ছাপত, আর দোকানি ভাবতেন টাকা জমা হয়ে গেছে।
 *
 * দাবি — একই আদায়: খসড়া থাকতে মাথায় "খসড়া" বাক্স আর "খসড়া" জলছাপ; পাকা হলে দুটোর কোনোটাই নয়।
 */
final class ADraftReceiptSaysItIsADraftTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_draft_receipt_says_draft_and_a_posted_one_does_not(): void
    {
        $service = app(CollectionService::class);
        $collection = $service->create([
            'customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'amount' => '500',
            'instrument' => 'cash',
        ], [])->fresh();

        $this->assertStringContainsString(__('core.print.draft_receipt_notice'), $this->receiptHtml($collection),
            '⛔ খসড়া টাকার রসিদে "খসড়া" লেখা নেই — পাকা রসিদের মতোই দেখায়।');
        $this->assertSame(__('core.print.draft_watermark'), $this->watermarkFor($collection->fresh()), '⛔ খসড়া রসিদে জলছাপ নেই।');

        $service->confirm($collection->fresh());

        $this->assertStringNotContainsString(__('core.print.draft_receipt_notice'), $this->receiptHtml($collection->fresh()),
            '⛔ পাকা হওয়া রসিদেও "খসড়া" লেখা।');
        $this->assertNull($this->watermarkFor($collection->fresh()), '⛔ পাকা হওয়া রসিদেও জলছাপ।');
    }

    private function receiptHtml(Collection $collection): string
    {
        $seen = [];
        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('sales.print.receipt', $collection))->assertOk();
        $this->assertNotSame([], $seen, 'রসিদের ছাঁচ ডাকাই হয়নি।');

        return view('print.document', $seen)->render();
    }

    private function watermarkFor(Collection $collection): ?string
    {
        return (new ReflectionMethod(SalesPrintController::class, 'watermarkFor'))
            ->invoke(app(SalesPrintController::class), $collection);
    }
}
