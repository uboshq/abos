<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\MasterData\Models\Tax;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * নিষ্ক্রিয় খাত করের ফর্ম থেকে নীরবে মুছে যেত — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ ড্রপডাউন কেবল সক্রিয় খাত দেখাত: খাতটা পরে নিষ্ক্রিয় হলে সম্পাদনার ফর্মে "—" আসত, আর কেবল নাম বদলে সংরক্ষণ করলেই
 * করের খাত খালি হয়ে যেত; যাচাইও ঐ খাত চিনত না। ⓘ নতুন করে নিষ্ক্রিয় খাত বাছা এখনো যায় না — দাবি সেটাও দেখে।
 */
final class AClosedAccountVanishedFromTheTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_inactive_account_already_on_a_tax_stays_through_an_edit(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        [$kept, $other] = Account::query()->postable()->active()->orderBy('code')->take(2)->get()->all();
        $tax = Tax::query()->create(['code' => 'TKEEP', 'name_en' => 'Kept tax', 'rate' => 5, 'kind' => 'vat',
            'account_id' => $kept->id, 'is_active' => true]);
        DB::table($kept->getTable())->whereKey($kept->id)->update(['is_active' => false]);
        DB::table($other->getTable())->whereKey($other->id)->update(['is_active' => false]);

        $this->get('/master-data/taxes/'.$tax->id.'/edit')->assertOk()
            ->assertSee('value="'.$kept->id.'" selected', false);

        $this->put('/master-data/taxes/'.$tax->id, [
            'code' => 'TKEEP', 'name_en' => 'Kept tax renamed', 'rate' => '5', 'kind' => 'vat', 'account_id' => $kept->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame((int) $kept->id, (int) $tax->fresh()->account_id, 'নাম বদলাতেই করের খাত মুছে গেল।');

        $this->put('/master-data/taxes/'.$tax->id, [
            'code' => 'TKEEP', 'name_en' => 'Kept tax renamed', 'rate' => '5', 'kind' => 'vat', 'account_id' => $other->id,
        ])->assertSessionHasErrors('account_id');
    }
}
