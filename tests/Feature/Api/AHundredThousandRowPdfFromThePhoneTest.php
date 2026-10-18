<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReportExportApiController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ ফোন এক লাখ সারির PDF চাইতে পারত (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[ReportExportApiController]])।
 *
 * ⚠️ mPDF পুরো পাতা স্মৃতিতে বানায়; সারির সীমা সব ফরম্যাটে এক লাখ, তাই একটা অনুরোধেই PHP-র স্মৃতি আর কর্মী আটকানো যেত।
 * ⭐ এখন PDF-এ ৩,০০০-এর বেশি হলে কারণসহ ফেরত; csv/xlsx আগের মতো।
 */
final class AHundredThousandRowPdfFromThePhoneTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'cbapi.three_thousand_and_one';

    public function test_a_pdf_beyond_the_pdf_cap_is_refused_and_csv_is_not(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        app(ReportEngine::class)->register(new ReportDefinition(
            key: self::KEY,
            title: 'Counting',
            query: function (array $f) {
                $digits = DB::query()->selectRaw('0 as n');
                for ($i = 1; $i <= 9; $i++) {
                    $digits->unionAll(DB::query()->selectRaw("{$i} as n"));
                }

                return DB::query()->fromSub($digits, 'a')
                    ->crossJoinSub($digits, 'b')->crossJoinSub($digits, 'c')->crossJoinSub($digits, 'd')
                    ->selectRaw('(a.n + b.n * 10 + c.n * 100 + d.n * 1000) as n')
                    ->whereRaw('(a.n + b.n * 10 + c.n * 100 + d.n * 1000) <= ?', [ReportExportApiController::MAX_PDF_ROWS])
                    ->orderByRaw('n');
            },
            columns: [['key' => 'n', 'label' => 'N', 'type' => ReportColumn::TEXT]],
            filters: [],
            permission: 'customer.report',
        ));

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($owner->fresh(), [AuthController::APP]);

        $this->get('/api/v1/reports/'.self::KEY.'/export?format=pdf', ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->get('/api/v1/reports/'.self::KEY.'/export?format=csv', ['Accept' => 'application/json'])
            ->assertOk();
    }
}
