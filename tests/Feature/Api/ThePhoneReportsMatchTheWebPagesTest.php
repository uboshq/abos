<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * ⭐ ফোনের রিপোর্ট ওয়েবের পাতার সাথে মেলে — a5-এর ভাগের নয়টা মডিউল (মালিক, ১৭ অক্টোবর ২০২৬: "direct webpage app e bujajayna";
 * a6-এর যাচাইয়ের তালিকা গ, চ আর ঙ)।
 *
 * ⓘ প্রতিটা ওয়েব-কন্ট্রোলারের নিজের `SLUGS` মানচিত্র ধরে — slug → রিপোর্টের চাবি। একই মানুষ, একই তারিখ: ওয়েবের পাতা (`result`) আর
 * ফোনের দরজা (`/api/v1/reports/{key}`) — সারির গোনা, প্রতিটা যোগের কলামের মোট আর দেখানো কলামের তালিকা এক হতে হবে; আর ফোনের PDF
 * (`/reports/{key}/export?format=pdf`) একই তারিখে তৈরি হতে হবে।
 * ⓘ সব অমিল একসাথে বলা হয় (মডিউল · slug · কী ভুল), একটায় থেমে নয় — যাচাইয়ের তালিকাটাই এই দাবির ফল।
 */
final class ThePhoneReportsMatchTheWebPagesTest extends TestCase
{
    use RefreshDatabase;

    /** controller → [ওয়েবের রাউটের নাম, ফোনের মডিউল-সুইচ] */
    private const CONTROLLERS = [
        \App\Modules\Sales\Http\Controllers\SalesReportController::class => ['sales.report.show', 'sales'],
        \App\Modules\Sales\Http\Controllers\MarginReportController::class => ['sales.margin.report.show', 'sales'],
        \App\Modules\Sales\Http\Controllers\SalesReturnReasonReportController::class => ['sales.return.report.show', 'sales'],
        \App\Modules\Purchase\Http\Controllers\PurchaseReportController::class => ['purchase.report.show', 'purchase'],
        \App\Modules\Customer\Http\Controllers\CustomerReportController::class => ['customer.report.show', 'customer'],
        \App\Modules\Supplier\Http\Controllers\SupplierReportController::class => ['supplier.report.show', 'supplier'],
        \App\Modules\Promotion\Http\Controllers\PromotionReportController::class => ['promotion.report.show', 'promotion'],
        \App\Modules\CreditControl\Http\Controllers\CreditReportController::class => ['credit_control.report.show', 'credit_control'],
        \App\Modules\Executive\Http\Controllers\ExecutiveReportController::class => ['executive.report.show', 'executive'],
        \App\Modules\Documents\Http\Controllers\DocumentReportController::class => ['documents.report.show', 'documents'],
    ];

    public function test_every_report_in_a5s_share_reads_the_same_on_the_phone_as_on_the_web(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach (array_unique(array_column(self::CONTROLLERS, 1)) as $module) {
            app(SettingsService::class)->set(PhoneModules::key($module), true);
        }

        $dates = ['from' => now()->startOfMonth()->subMonths(2)->toDateString(), 'to' => now()->toDateString()];
        $problems = [];
        $checked = 0;

        foreach (self::CONTROLLERS as $controller => [$route, $module]) {
            $slugs = (new ReflectionClassConstant($controller, 'SLUGS'))->getValue();

            foreach ($slugs as $slug => $key) {
                $where = class_basename($controller).' · '.$slug.' ('.$key.')';

                $this->app['auth']->forgetGuards();
                $web = $this->actingAs($owner)->get(route($route, ['slug' => $slug] + $dates));
                CompanyContext::set($company->id, $company->defaultBranch()?->id);

                if ($web->status() !== 200) {
                    $problems[] = "{$where}: ওয়েবের পাতা {$web->status()}";

                    continue;
                }

                $result = $web->viewData('result');

                if (! $result instanceof \App\Core\Engines\Report\ReportResult) {
                    // ⓘ ইঞ্জিনের রিপোর্ট নয় (নিজের পাতা) — ফোনের সাধারণ রিপোর্ট-পর্দার কাজ নয়
                    continue;
                }

                $this->app['auth']->forgetGuards();
                Sanctum::actingAs($owner->fresh(), [AuthController::APP]);
                $phone = $this->getJson('/api/v1/reports/'.$key.'?'.http_build_query($dates + ['perPage' => 200]));
                CompanyContext::set($company->id, $company->defaultBranch()?->id);

                if ($phone->status() !== 200) {
                    $problems[] = "{$where}: ফোনের দরজা {$phone->status()}";

                    continue;
                }

                $checked++;

                if ((int) $phone->json('totalRows') !== $result->totalRows) {
                    $problems[] = "{$where}: সারি ওয়েবে {$result->totalRows}, ফোনে ".$phone->json('totalRows');
                }

                $webColumns = array_map(fn ($c) => $c->key, $result->columnsFor($owner));
                $phoneColumns = array_column($phone->json('columns'), 'key');
                if ($webColumns !== $phoneColumns) {
                    $problems[] = "{$where}: কলাম মেলে না — ওয়েব [".implode(',', $webColumns).'], ফোন ['.implode(',', $phoneColumns).']';
                }

                // ⭐ ঙ — ফোনের PDF একই তারিখে তৈরি হয় (দেখা, ছাপা, পাঠানো)
                $this->app['auth']->forgetGuards();
                Sanctum::actingAs($owner->fresh(), [AuthController::APP]);
                $pdf = $this->get('/api/v1/reports/'.$key.'/export?'.http_build_query($dates + ['format' => 'pdf']));
                CompanyContext::set($company->id, $company->defaultBranch()?->id);
                if ($pdf->status() !== 200 || ! str_starts_with((string) $pdf->headers->get('Content-Type'), 'application/pdf')) {
                    $problems[] = "{$where}: PDF হয়নি ({$pdf->status()}, ".$pdf->headers->get('Content-Type').')';
                }

                foreach ((array) $phone->json('totals') as $column => $value) {
                    $want = $result->totals[$column] ?? null;
                    if ($want === null || $value === null || bccomp((string) $want, (string) $value, 2) !== 0) {
                        $problems[] = "{$where}: '{$column}'-এর মোট ওয়েবে ".var_export($want, true).', ফোনে '.var_export($value, true);
                    }
                }
            }
        }

        $this->assertGreaterThan(30, $checked, 'দাবির ভিত্তি নেই — প্রায় কোনো রিপোর্টই দুই দিকে চলেনি।');
        $this->assertSame([], $problems, "⛔ ফোন আর ওয়েবের রিপোর্ট মেলে না:\n".implode("\n", $problems));
    }
}
