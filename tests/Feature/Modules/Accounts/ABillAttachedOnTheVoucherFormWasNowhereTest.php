<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ভাউচারের ফর্মে জোড়া বিল কোথাও দেখা যেত না, আর নামাতে গেলে ৪০৪ — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ⛔ [[VoucherWriter]] কাগজটা ক্লাসের নামে রাখত (`App\Modules\Accounts\Models\Voucher`), অথচ ভাউচারের পাতা, সইয়ের পাতা আর
 * নামানোর দরজা সবাই খোঁজে `'voucher'` ([[Voucher::drillSourceType()]])। হিসাবরক্ষক সরবরাহকারীর বিল জুড়ে দিতেন, আর সইদাতা
 * দেখতেন "কোনো কাগজ নেই"।
 */
final class ABillAttachedOnTheVoucherFormWasNowhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bill_attached_on_the_form_shows_on_the_voucher_and_downloads(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $company = Company::query()->findOrFail($owner->current_company_id);
        CompanyContext::set($company->id, $owner->current_branch_id ?? $company->defaultBranch()?->id);
        $this->actingAs($owner);

        $this->post(route('accounts.voucher.store', Voucher::EXPENSE), [
            'type' => Voucher::EXPENSE,
            'trx_date' => now()->toDateString(),
            'from_account_id' => (int) Account::query()->money()->postable()->active()->orderBy('code')->value('id'),
            'to_account_id' => (int) Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->value('id'),
            'amount' => '750',
            'narration' => 'BILL-ON-THE-FORM',
            'save_as_draft' => 1,
            'attachment' => UploadedFile::fake()->image('supplier-bill.jpg', 40, 40),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $paper = Attachment::query()->where('source_entity_id', $voucher->id)->where('original_name', 'supplier-bill.jpg')->first();
        $this->assertNotNull($paper, 'ফর্মের বিলটা জমাই হয়নি — দাবিটা কিছু মাপছে না।');
        $this->assertSame(Voucher::drillSourceType(), $paper->source_entity, 'বিলটা ভাউচারের ধরনের নামে জমা হয়নি।');

        $this->get(route('accounts.voucher.show', $voucher))->assertOk()->assertSee('supplier-bill.jpg');
        $this->get(route('attachment.download', $paper))->assertOk();
    }
}
