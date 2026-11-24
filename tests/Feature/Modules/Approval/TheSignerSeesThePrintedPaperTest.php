<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Services\PaperPreview;
use App\Core\Services\PaperTrail;
use App\Core\Services\PhonePaperDoor;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⭐ "দেখুন" shows the paper as it prints, and looking is not printing (owner, 24 Nov 2026).
 *
 * Claims: the owner gets the voucher's PDF; nothing is written to the print trail; a person who may not read the paper gets 403; a
 * record with no printed paper offers no preview.
 */
final class TheSignerSeesThePrintedPaperTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_printed_voucher_is_shown_and_never_counted_as_printed(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id, 'ui' => 'blue'])->save();

        $voucher = Voucher::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'type' => Voucher::RECEIPT,
            'financial_year_id' => \App\Models\FinancialYear::forDate(now()->toDateString())?->id,
            'document_no' => 'RCV-LOOK-1', 'trx_date' => now()->toDateString(), 'amount' => '500', 'status' => 'draft', 'created_by' => $owner->id,
        ]);
        $approval = Approval::create(['company_id' => $company->id, 'approvable_type' => Voucher::class, 'approvable_id' => $voucher->id,
            'module' => 'accounts', 'action' => Voucher::RECEIPT, 'amount' => '500', 'status' => Approval::PENDING, 'current_level' => 1,
            'requested_by' => $owner->id, 'requested_at' => now()]);

        $before = DocumentDelivery::query()->withoutGlobalScopes()->count();
        $paper = $this->actingAs($owner)->get(route('approval.inbox.paper', $approval->id));
        $paper->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $paper->getContent(), '⛔ the preview is not the printed paper.');
        $this->assertSame($before, DocumentDelivery::query()->withoutGlobalScopes()->count(), '⛔ looking was counted as printing.');

        $stranger = User::factory()->create(['is_active' => true]);
        $stranger->companies()->attach($company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($stranger->fresh())->get(route('approval.inbox.paper', $approval->id))->assertForbidden();

        $this->assertSame('accounts_voucher', app(PaperPreview::class)->typeOf($voucher));
        $this->assertNull(app(PaperPreview::class)->typeOf($company), '⛔ a record with no printed paper offers a preview.');
    }

    /** ⓘ The one-action controller (voucher, transfer) was called by a method it does not have (demo 500, 24 Nov 2026). */
    public function test_every_printed_paper_has_an_action_the_preview_can_call_and_that_returns_a_response(): void
    {
        $printable = app(PhonePaperDoor::class)->printable();
        $this->assertGreaterThan(5, count($printable), 'The list is empty — the claim looks at nothing.');

        $broken = [];
        foreach ($printable as $name => [$type, $class]) {
            $route = Route::getRoutes()->getByName(PaperTrail::DOCUMENT_ROUTES[$type]);
            $controller = $route->getController();
            // ⓘ the same choice [[PaperPreview::render()]] makes
            $method = method_exists($controller, $route->getActionMethod()) ? $route->getActionMethod() : '__invoke';

            if (! method_exists($controller, $method) || ! (new ReflectionMethod($controller, $method))->isPublic()) {
                $broken[] = "{$name}: {$type} has no public action {$method}";

                continue;
            }

            $action = new ReflectionMethod($controller, $method);
            $takes = array_map(fn ($p) => (string) $p->getType(), $action->getParameters());
            if ($action->getNumberOfRequiredParameters() !== 2 || ! is_a($takes[0] ?? '', Request::class, true) || ($takes[1] ?? '') !== $class) {
                $broken[] = "{$name}: {$type} wants (".implode(', ', $takes).'), the preview gives (Request, '.class_basename($class).')';
            }
            if (! is_a((string) $action->getReturnType(), Response::class, true)) {
                $broken[] = "{$name}: {$type} returns ".$action->getReturnType().', not a Response';
            }
        }

        $this->assertSame([], $broken, "⛔ the signer's preview cannot open:\n".implode("\n", $broken));
    }
}
