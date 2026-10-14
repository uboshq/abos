<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাস্টার তালিকার সংখ্যার ঘরে সীমা ছিল না — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ সব সংখ্যার ঘর কেবল `numeric`: বাকির শর্তে −১০ দিন বা ৭০০০০ দিন (unsigned smallint) ৫০০ দিত, করের হার ১৫০% বসত,
 * একক-রূপান্তরের গুণক ০ বসত (প্রতিটা রূপান্তর শূন্য), আর সম্পাদনায় `1e3` [[MasterListService::sameValue()]]-এর
 * `bccomp()`-এ ৫০০ দিত। ⓘ ঠিক মানগুলো আগের মতোই বসে — দাবি সেটাও দেখে।
 */
final class ANegativeDaysTermGaveAServerErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_number_boxes_keep_to_their_columns_and_to_plain_numbers(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        foreach (['-10', '70000', '2.5'] as $bad) {
            $this->post('/master-data/payment-terms', ['name_en' => 'Term '.$bad, 'days' => $bad])
                ->assertRedirect()->assertSessionHasErrors('days');
        }
        $this->post('/master-data/payment-terms', ['name_en' => 'Net forty five', 'days' => '45'])->assertSessionHasNoErrors();

        $this->post('/master-data/taxes', ['name_en' => 'Too much tax', 'rate' => '150', 'kind' => 'vat'])
            ->assertRedirect()->assertSessionHasErrors('rate');

        $base = Unit::query()->create(['code' => 'TPCS', 'name_en' => 'Test piece', 'factor' => 1, 'is_active' => true]);
        $unit = Unit::query()->create(['code' => 'TDZN', 'name_en' => 'Test dozen', 'base_unit_id' => $base->id, 'factor' => 12, 'is_active' => true]);
        foreach (['0', '-1', '1e3'] as $bad) {
            $this->put('/master-data/units/'.$unit->id, [
                'code' => $unit->code, 'name_en' => $unit->name_en, 'base_unit_id' => $unit->base_unit_id, 'factor' => $bad,
            ])->assertRedirect()->assertSessionHasErrors('factor');
        }
        $this->assertSame(1, bccomp((string) $unit->fresh()->factor, '0', 6), 'গুণক শূন্য বা ঋণাত্মক হয়ে বসল।');
    }
}
