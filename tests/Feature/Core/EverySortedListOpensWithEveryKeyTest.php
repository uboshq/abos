<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * ⭐ পুরো বারের সাজানো — প্রতিটা তালিকা প্রতিটা চাবিতে খোলে, আর বারে সেই ড্রপডাউন থাকে ([[ListSort]], ২৭ নভেম্বর ২০২৬;
 * জরিপ পুরো-বার-জরিপ-২৬-নভেম্বর.html)।
 *
 * ⓘ কেবল নতুন সাজানো পাওয়া পাতা — চাবিগুলো পাতা নিজে বারে দেখায় (`name="sort"`-এর `<option>`), এখান থেকে পড়া হয়; তাই ড্রপডাউনের
 *   প্রতিটা চাবি সত্যিই খোলে কি না দেখা হয় (ভুল কলামের নাম হলে SQL ৫০০)। ⓘ মালিক সব চাবির মালিক — অনুমতির প্রশ্ন এখানে নয়।
 */
final class EverySortedListOpensWithEveryKeyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<class-string, string> নিয়ন্ত্রক => পদ্ধতি */
    private const PAGES = [
        \App\Modules\Accounts\Http\Controllers\AssetCategoryController::class => 'index',
        \App\Modules\Accounts\Http\Controllers\AssetVerificationController::class => 'index',
        \App\Modules\Accounts\Http\Controllers\BankReconciliationController::class => 'index',
        \App\Modules\Accounts\Http\Controllers\ComplianceObligationController::class => 'index',
        \App\Modules\Accounts\Http\Controllers\FixedAssetController::class => 'index',
        \App\Modules\Accounts\Http\Controllers\InterCompanyController::class => 'index',
        \App\Modules\Accounts\Http\Controllers\LoanController::class => 'index',
        \App\Modules\Approval\Http\Controllers\ApprovalInboxController::class => 'mine',
        \App\Modules\Finance\Http\Controllers\BankChargeController::class => 'index',
        \App\Modules\Finance\Http\Controllers\BankFacilityController::class => 'index',
        \App\Modules\Finance\Http\Controllers\CapitalController::class => 'index',
        \App\Modules\Finance\Http\Controllers\DepositKindController::class => 'index',
        \App\Modules\Finance\Http\Controllers\InstitutionController::class => 'index',
        \App\Modules\Finance\Http\Controllers\InsuranceController::class => 'index',
        \App\Modules\Finance\Http\Controllers\RentalContractController::class => 'index',
        \App\Modules\Finance\Http\Controllers\TenancyController::class => 'index',
        \App\Modules\Finance\Http\Controllers\WithdrawalController::class => 'index',
        \App\Modules\Governance\Http\Controllers\ErrorLogController::class => 'index',
        \App\Modules\Governance\Http\Controllers\ExportLogController::class => 'index',
        \App\Modules\MasterData\Http\Controllers\LocationController::class => 'index',
        \App\Modules\Notification\Http\Controllers\NotificationArchiveController::class => 'index',
        \App\Modules\Notification\Http\Controllers\NotificationAuditController::class => 'index',
        \App\Modules\Notification\Http\Controllers\NotificationDeliveryController::class => 'logs',
        \App\Modules\Notification\Http\Controllers\NotificationEscalationController::class => 'index',
        \App\Modules\Promotion\Http\Controllers\PromotionController::class => 'index',
        \App\Modules\Purchase\Http\Controllers\PurchaseContractController::class => 'index',
        \App\Modules\Sales\Http\Controllers\DeliveryStageController::class => 'index',
        \App\Modules\Sales\Http\Controllers\DepositClaimController::class => 'index',
        \App\Modules\Sales\Http\Controllers\DepotCheckController::class => 'index',
        \App\Modules\Sales\Http\Controllers\DirectSaleController::class => 'drafts',
        \App\Modules\Sales\Http\Controllers\GatePassController::class => 'index',
        \App\Modules\Sales\Http\Controllers\LeadController::class => 'index',
        \App\Modules\Sales\Http\Controllers\LoadingSheetController::class => 'index',
        \App\Modules\Sales\Http\Controllers\OpportunityController::class => 'index',
        \App\Modules\Sales\Http\Controllers\PriceBookController::class => 'index',
        \App\Modules\Sales\Http\Controllers\PriceListController::class => 'index',
        \App\Modules\Sales\Http\Controllers\RouteAccountController::class => 'index',
        \App\Modules\Sales\Http\Controllers\TransportAssignmentController::class => 'index',
        \App\Modules\SystemAdmin\Http\Controllers\BranchController::class => 'index',
        \App\Modules\SystemAdmin\Http\Controllers\CompanyController::class => 'index',
        \App\Modules\SystemAdmin\Http\Controllers\UserController::class => 'index',
    ];

    public function test_every_sorted_list_offers_its_keys_and_opens_with_each(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        // ⓘ সাজানোর ড্রপডাউন সব রূপে আছে; ABOS রূপে বার এক সারির — দামের বই আর দামের তালিকায় বার কেবল এখানে
        $owner->forceFill(['ui' => 'rose'])->save();
        $this->actingAs($owner->fresh());

        $problems = [];
        foreach (self::PAGES as $controller => $method) {
            $route = $this->routeOf($controller, $method);
            if ($route === null) {
                $problems[] = "{$controller}@{$method}: রুট খুঁজে পাওয়া গেল না";

                continue;
            }

            $url = url($route->uri());
            $page = $this->get($url);
            if ($page->status() !== 200) {
                $problems[] = "{$url}: সাজানো ছাড়া {$page->status()}";

                continue;
            }

            $keys = $this->sortKeys((string) $page->getContent());
            if ($keys === []) {
                $problems[] = "{$url}: বারে সাজানোর ড্রপডাউন নেই";

                continue;
            }

            foreach ($keys as $key) {
                $status = $this->get($url.'?sort='.urlencode($key))->status();
                if ($status !== 200) {
                    $problems[] = "{$url}?sort={$key}: {$status}";
                }
            }
        }

        $this->assertSame([], $problems, "⛔ সাজানো ভাঙা:\n".implode("\n", $problems));
    }

    private function routeOf(string $controller, string $method): ?Route
    {
        foreach (Router::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && $route->getActionName() === $controller.'@'.$method
                && $route->parameterNames() === []) {
                return $route;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function sortKeys(string $html): array
    {
        if (! preg_match('/<select[^>]*name="sort"[^>]*>(.*?)<\/select>/s', $html, $m)) {
            return [];
        }

        preg_match_all('/<option[^>]*value="([^"]*)"/', $m[1], $v);

        return array_values(array_filter($v[1], fn ($k) => $k !== ''));
    }
}
