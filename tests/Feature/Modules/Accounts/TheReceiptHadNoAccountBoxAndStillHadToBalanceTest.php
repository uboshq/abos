<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * পর্দায় ঘরটা নেই, তবু খাতার দুই পাশ লাগে।
 *
 * ── ⛔ কী ঘটেছিল, ১৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * নমুনার রসিদ পর্দায় "কার কাছ থেকে" নামে কোনো ঘর নেই — উপরে ডিপোজিটর
 * বাছা হয়েই যায়, আর সেটাই বলে দেয় টাকা কার কাছ থেকে।
 *
 * ⚠️ ঘরটা সরানোর সাথে সাথে **প্রতিটা রসিদ জমা দিতে গিয়ে আটকাত**:
 * `from_account_id` যাচাইয়ে `required`, আর পর্দা আর সেটা পাঠাত না।
 *
 * ⓘ ভুলটা নীরব হত না — ব্যবহারকারী একটা ভ্যালিডেশন বার্তা দেখতেন।
 * ⛔ কিন্তু সেটা আরও খারাপ: বার্তাটা এমন একটা ঘরের কথা বলত **যা পর্দায়
 * নেই**, তাই তিনি কিছুই করতে পারতেন না।
 *
 * ── ⭐ কেন এই পরীক্ষাটা সত্যিকারের POST করে ─────────────────────────
 * পর্দা রেন্ডার হওয়া প্রমাণ করে ঘরগুলো আছে; সেটা **প্রমাণ করে না যে
 * সংরক্ষণ হয়**। ⓘ আজ সারাদিন ঠিক ঐ তফাতটাই বারবার ধরা পড়েছে — ঘর
 * ছিল, সেভ হত না; পাহারা সবুজ ছিল, কোড চলত না।
 */
final class TheReceiptHadNoAccountBoxAndStillHadToBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /**
     * ⭐ পক্ষ বাছলেই ক্রেডিট পাশটা প্রাপ্য হিসাবে বসে।
     */
    public function test_a_receipt_without_a_from_box_still_posts(): void
    {
        [$user, $company] = $this->owner();

        $customer = DB::table('customers')->where('company_id', $company->id)->first();
        $this->assertNotNull($customer, 'ডেমোতে একজন গ্রাহকও নেই — ফিক্সচারটাই ফাঁকা।');

        $cash = DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('code', 'like', StandardChart::CASH_IN_HAND.'%')
            ->where('is_group', false)
            ->value('id');

        $this->assertNotNull($cash, 'নগদের পাতা-খাত পাওয়া গেল না।');

        $response = $this->actingAs($user)->post('/accounts/vouchers/receipt', [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'to_account_id' => $cash,
            'amount' => '1500.00',
            'narration' => 'পর্দায় "কার কাছ থেকে" ঘর নেই — পক্ষ থেকেই আসা উচিত',
            'instrument' => 'cash',
        ]);

        $response->assertSessionHasNoErrors();

        $voucher = Voucher::query()->where('type', Voucher::RECEIPT)->latest('id')->first();

        $this->assertNotNull($voucher, implode("\n", [
            'রসিদটা সংরক্ষিত হয়নি।',
            '',
            '⛔ সম্ভবত from_account_id খালি — পর্দা ওটা আর পাঠায় না,',
            'আর VoucherRequest::fillAccountFromParty() সেটা ভরতে পারেনি।',
        ]));

        /*
         * ⭐ আসল দাবি: ক্রেডিট পাশটা প্রাপ্য হিসাবে গেছে।
         *
         * ⓘ গ্রাহক টাকা দিলে তাঁর দেনা কমে, তাই ক্রেডিট ১১১০-এ।
         * ⚠️ ভুল খাতে গেলে ভাউচারটা দিব্যি পোস্ট হত, খতিয়ানও মিলত —
         * কেবল বকেয়ার তালিকা কোনোদিন কমত না।
         */
        $receivable = DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('code', StandardChart::RECEIVABLE)
            ->value('id');

        $credited = $voucher->lines
            ->first(fn ($l) => bccomp((string) $l->credit, '0', 4) > 0);

        $this->assertNotNull($credited, 'ক্রেডিট সারিটাই নেই — দাখিলা অসম্পূর্ণ।');

        $this->assertSame((int) $receivable, (int) $credited->account_id, implode("\n", [
            'ক্রেডিট পাশটা প্রাপ্য হিসাবে যায়নি।',
            '',
            '⚠️ ভাউচারটা পোস্ট হবে, খতিয়ানও মিলবে — কেবল গ্রাহকের',
            'বকেয়া কোনোদিন কমবে না, আর কেউ বলতে পারবে না কেন।',
        ]));
    }

    /**
     * ⓘ পক্ষ না বাছলে যাচাই সৎভাবে আটকায় — নীরবে বসে যায় না।
     */
    public function test_without_a_party_the_form_refuses_instead_of_guessing(): void
    {
        [$user, $company] = $this->owner();

        $cash = DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('code', 'like', StandardChart::CASH_IN_HAND.'%')
            ->where('is_group', false)
            ->value('id');

        $this->actingAs($user)->post('/accounts/vouchers/receipt', [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'to_account_id' => $cash,
            'amount' => '900.00',
            'instrument' => 'cash',
        ])->assertSessionHasErrors('from_account_id');
    }

    /** @return array{0: User, 1: Company} */
    private function owner(): array
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $user->switchCompany($company->id);

        return [$user, $company];
    }
}
