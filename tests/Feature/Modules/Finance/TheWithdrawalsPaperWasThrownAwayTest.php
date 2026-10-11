<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * উত্তোলনের কাগজ ফর্মে দেওয়া যেত, কিন্তু রাখা হত না — পুরো-ERP অডিট, অর্থ S20, ১০ অক্টোবর ২০২৬।
 *
 * ⭐ কাগজটা উত্তোলনের সারির নামে থাকে (ব্যাংক সুবিধার একই ছাঁচ); কাগজ না দিলে কিছুই বসে না।
 */
final class TheWithdrawalsPaperWasThrownAwayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_paper_is_kept_against_the_withdrawal(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $person = Person::query()->create(['code' => 'P-S20', 'name_en' => 'Paper Owner', 'name_bn' => 'Paper Owner', 'is_active' => true]);

        $this->post(route('finance.withdrawal.store'), [
            'person_id' => $person->id, 'amount' => '5000', 'trx_date' => now()->toDateString(),
            'paper' => UploadedFile::fake()->image('letter.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $withdrawal = Withdrawal::query()->where('person_id', $person->id)->latest('id')->first();
        $this->assertNotNull($withdrawal, 'দৃশ্যটাই বানানো যায়নি — উত্তোলন বসেনি');
        $this->assertSame(1, Attachment::query()->where('source_entity', Withdrawal::drillSourceType())->where('source_entity_id', $withdrawal->id)->count(),
            '⛔ উত্তোলনের কাগজ রাখা হয়নি');

        // ⓘ কাগজ ছাড়া — কিছুই বসে না
        $this->post(route('finance.withdrawal.store'), ['person_id' => $person->id, 'amount' => '1000', 'trx_date' => now()->toDateString()])
            ->assertRedirect()->assertSessionHasNoErrors();
        $second = Withdrawal::query()->where('person_id', $person->id)->latest('id')->first();
        $this->assertNotSame($withdrawal->id, $second->id);
        $this->assertSame(0, Attachment::query()->where('source_entity', Withdrawal::drillSourceType())->where('source_entity_id', $second->id)->count());
    }
}
