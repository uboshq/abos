<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\ImportRunner;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * পণ্য আমদানির পূর্বদর্শন একক চাইত না, আর ফাইলের ভিতরের যমজ দেখত না — Inventory অডিট ম২৫, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ এককহীন সারি "ঠিক" বলে ঢুকত (পরে প্রথম কাগজেই থামত); একই ফাইলে একই বারকোড বা একই নাম দুইবার থাকলে
 * পূর্বদর্শন দুটোকেই ঠিক বলত — বসানোর সময় বারকোডেরটা ভাঙত, নামেরটা দুইটা পণ্য বানাত।
 * ⭐ এখন একক বাধ্যতামূলক, আর ফাইলের ভিতরের দ্বিতীয় বারকোড/নাম, আর দেখা শাখায় আগে থেকে থাকা নাম, পূর্বদর্শনেই ধরা পড়ে।
 */
final class TheImportPreviewMissedTheTwinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_preview_wants_a_unit_and_catches_a_barcode_or_name_given_twice(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $unit = (string) Unit::query()->orderBy('id')->firstOrFail()->code;
        $known = (string) Product::query()->orderBy('id')->value('name_en');

        $result = app(ImportRunner::class)->check('product', $this->csv(
            "code,name_en,name_bn,barcode,brand,category,unit,tax,purchase_price,sale_price,reorder_level\n"
            ."M25-1,Twin Soap,,8800000025001,,,{$unit},,10,12,0\n"     // ঠিক
            ."M25-2,Other Soap,,8800000025001,,,{$unit},,10,12,0\n"    // একই বারকোড
            ."M25-3,twin  soap,,,,,{$unit},,10,12,0\n"                  // একই নাম (ছোট হাত, দুই ফাঁকা)
            ."M25-4,No Unit Soap,,,,,,,10,12,0\n"                       // একক নেই
            ."M25-5,Fine Soap,,8800000025005,,,{$unit},,10,12,0\n"     // ঠিক
            ."M25-6,{$known},,,,,{$unit},,10,12,0\n",                   // আগে থেকেই আছে এমন নাম
        ));

        $bad = collect($result['rows'])->mapWithKeys(fn ($row) => [$row['data']['code'] => $row['errors'] !== []]);

        $this->assertFalse($bad['M25-1'], 'প্রথম সারিটাই আটকাল।');
        $this->assertTrue($bad['M25-2'], '⛔ একই ফাইলে একই বারকোড দুইবার, পূর্বদর্শন ঠিক বলল।');
        $this->assertTrue($bad['M25-3'], '⛔ একই ফাইলে একই নাম দুইবার, পূর্বদর্শন ঠিক বলল।');
        $this->assertTrue($bad['M25-4'], '⛔ এককহীন পণ্য পূর্বদর্শনে ঠিক।');
        $this->assertFalse($bad['M25-5'], 'ঠিক সারিও আটকাল।');
        $this->assertTrue($bad['M25-6'], '⛔ আগে থেকেই থাকা নামের পণ্য আবার ঢুকছিল।');
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'abos-m25').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }
}
