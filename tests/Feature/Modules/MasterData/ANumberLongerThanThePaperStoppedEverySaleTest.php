<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাগজের ঘরের চেয়ে লম্বা নম্বরের ছক বসে যেত — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ ছক ৬৪ অক্ষরের মধ্যে, উপসর্গ ১৬ — কিন্তু `{PREFIX}` কয়েকবার দিলে নম্বর কাগজের নম্বর-কলাম (সবচেয়ে সরুটা ৩২) ছাড়াত,
 * আর ঐ ধরনের প্রতিটা কাগজ বানাতে ৫০০। ⓘ সাধারণ ছক আগের মতোই বসে।
 */
final class ANumberLongerThanThePaperStoppedEverySaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_format_whose_number_cannot_fit_a_paper_is_refused(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $series = NumberSeries::query()->whereNull('branch_id')->orderBy('id')->firstOrFail();
        $before = $series->format;

        $this->put(route('master_data.series.update', $series), [
            'prefix' => 'ABCDEFGHIJKLMNOP', 'suffix' => '', 'padding' => 6,
            'format' => '{PREFIX}{PREFIX}{PREFIX}-{SEQ}',
        ])->assertRedirect()->assertSessionHasErrors('format');
        $this->assertSame($before, $series->fresh()->format, 'কাগজে ধরে না এমন ছক বসে গেল।');

        $this->put(route('master_data.series.update', $series), [
            'prefix' => 'INV', 'suffix' => '', 'padding' => 6, 'format' => '{PREFIX}-{FY}-{SEQ}',
        ])->assertSessionHasNoErrors();
        $this->assertSame('{PREFIX}-{FY}-{SEQ}', $series->fresh()->format, 'সাধারণ ছকও আটকে গেল — বেশি বন্ধ হয়েছে।');
    }
}
