<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ ছকের খাত না পেয়ে থামা কাগজ পর্দায় কারণ বলে — পাতার উপরের সাধারণ ঘরে (fe, ২৩ অক্টোবর ২০২৬)।
 *
 * <p>[[StandardChart::accountFor()]]-এর বার্তা বসে `account` ঘরে, যার পাশে কোনো ঘর আঁকা নেই। পাতার সাধারণ ভুলের বাক্স
 * ([[x-ui.errors]]) না দেখালে বোতাম চাপা হত, পাতা ফিরত, কাগজ খসড়াই থাকত, আর পর্দা একটা শব্দও বলত না। পথ: ভ্যাটসহ
 * ক্রেডিট নোট, ভ্যাট প্রদেয়ের (২১২০) কোডে ব্যাংক — নোটের পাতা থেকে নিশ্চিত।
 */
final class AChartStopSpeaksAtTheTopOfThePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_note_stopped_by_the_chart_says_why_on_its_page(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $note = app(NoteService::class)->create([
            'direction' => Note::CREDIT, 'party_type' => 'customer', 'party_id' => (int) Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'amount' => '1000', 'tax_amount' => '50', 'reason' => 'damage_claim',
            'narration' => 'ভ্যাটসহ ক্রেডিট নোট',
        ]);

        $vat = StandardChart::find(StandardChart::VAT_PAYABLE);
        $this->assertNotNull($vat, 'প্রস্তুতিটাই ভুল — ডেমোতে ভ্যাট প্রদেয় নেই');
        DB::table((new Account)->getTable())->where('id', $vat->id)->update([
            'name_en' => 'Depot Bank A/C', 'type' => Account::ASSET, 'money_kind' => Account::BANK, 'is_system' => false,
            'parent_id' => (int) StandardChart::find(StandardChart::BANK)->id,
        ]);

        $this->from(route('accounts.note.show', $note))->post(route('accounts.note.confirm', $note))
            ->assertRedirect(route('accounts.note.show', $note));
        $this->assertTrue($note->fresh()->isDraft(), 'প্রস্তুতিটাই ভুল — নোটটা থামেনি, পাকা হয়ে গেল');

        $page = (string) $this->get(route('accounts.note.show', $note))->assertOk()->getContent();
        preg_match('#<div role="alert".*?</div>#s', $page, $alert);

        $this->assertStringContainsString('(2120)', $alert[0] ?? '',
            '⛔ ছকের খাত না পেয়ে থামা নোটের পাতায় উপরের ভুলের বাক্সে কারণ নেই — বোতাম চাপা হল, কিছু বলা হল না');
    }
}
