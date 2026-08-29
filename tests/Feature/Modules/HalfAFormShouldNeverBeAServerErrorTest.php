<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\MasterData\Http\Controllers\MasterListController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * অর্ধেক ভরা ফর্মের উত্তর কখনো ৫০০ নয়।
 *
 * ── কী ভাঙা ছিল, ২৯ আগস্ট ২০২৬ ───────────────────────────────────────
 * HP-র রিপোর্ট: Master Data-র তালিকায় শুধু নাম লিখে Save চাপলে **HTTP
 * 500**। মালিক নিজেও লাইভে "bKash" বসাতে গিয়ে এতেই আটকেছিলেন।
 *
 * কারণটা এক লাইনের: `payment-methods`-এর `account_id` ঘরে কোনো `rules`
 * ছিল না, তাই যাচাইয়ে ওটা `nullable`; অথচ ডাটাবেজে কলামটা `NOT NULL`।
 * মজার ব্যাপার — ঘোষণার উপরের মন্তব্যে **লেখাই ছিল** "খাতটা
 * বাধ্যতামূলক"। মন্তব্য জানত, কোড জানত না।
 *
 * ── কেন এই পরীক্ষাটা স্কিমা পড়ে না, ফর্মটা চাপে ────────────────────
 * প্রথমে ভেবেছিলাম `information_schema` পড়ে `NOT NULL` কলামগুলো
 * যাচাইয়ের নিয়মের সাথে মিলিয়ে দেখব। ওটা ভুল উত্তর দিত: `code`-ও
 * সব টেবিলে `NOT NULL`, অথচ ঘরটা ঐচ্ছিক — কারণ সার্ভিস নাম থেকে কোড
 * বানিয়ে দেয়। স্কিমা-ভিত্তিক পরীক্ষা ওখানে মিথ্যা লাল হত, আর
 * ব্যতিক্রমের একটা হাতে লেখা তালিকা লাগত — যে তালিকা একদিন পুরনো হয়।
 *
 * তাই পরীক্ষাটা ঠিক সেটাই করে যা মালিক করেছিলেন: **শুধু নাম লিখে
 * Save**। উত্তর ৪২২ হলে ঠিক আছে — ফর্ম বলে দিয়েছে কী বাদ পড়েছে।
 * ৩০২ হলে ঠিক আছে — সারিটা বসে গেছে। **৫০০ মানে ভাঙা**, আর সেটাই
 * একমাত্র জিনিস যা এখানে ধরা হয়।
 *
 * ── কেন তালিকাটা হাতে লেখা নয় ───────────────────────────────────────
 * `KINDS` ধরে হাঁটা হয়, তাই আগামীকাল কেউ পঞ্চম একটা তালিকা যোগ করলে
 * সেটা প্রথম দিন থেকেই পাহারায় আসে। হাতে লেখা তালিকা হলে নতুনটাই
 * বাদ পড়ত — অর্থাৎ ঠিক যেটার জন্য পরীক্ষাটা দরকার।
 */
class HalfAFormShouldNeverBeAServerErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * নিয়ন্ত্রকের নিজের তালিকা — প্রতিফলন দিয়ে।
     *
     * `KINDS` private, আর সেটাই ঠিক: ওটা নিয়ন্ত্রকের ভেতরের গড়ন,
     * বাইরের কারও ব্যবহারের জিনিস নয়। কেবল পরীক্ষার জন্য public করলে
     * ভেতরের জিনিসটা বাইরের চুক্তি হয়ে যেত, আর পরের জন সেটা ধরে কোড
     * লিখত।
     *
     * @return array<string, array<string, mixed>>
     */
    private function kinds(): array
    {
        /** @var array<string, array<string, mixed>> $kinds */
        $kinds = (new ReflectionClass(MasterListController::class))
            ->getConstant('KINDS');

        return $kinds;
    }

    /**
     * প্রতিটা তালিকায় শুধু নাম লিখে Save — একটাতেও যেন ৫০০ না আসে।
     */
    public function test_no_master_list_answers_a_half_filled_form_with_a_server_error(): void
    {
        $broken = [];

        foreach (array_keys($this->kinds()) as $kind) {
            /*
             * ঠিকানাটা হাতে বানানো, রুটের নাম দিয়ে নয়।
             *
             * প্রতিটা তালিকার নিজের নামওয়ালা রুট (`master_data.brand.store`)
             * — নামটা `KINDS`-এর `route` ঘরে, আর ওটা slug থেকে আলাদা
             * (`cost-centers` → `cost_center`)। ঠিকানাটা slug ধরেই তৈরি
             * হয়, তাই ব্রাউজার যা করে পরীক্ষাও ঠিক তাই করে।
             */
            $response = $this->post('/master-data/'.$kind, ['name_en' => 'Guard Probe']);

            /*
             * ৫০০-এর নিচে যেকোনো কিছু গ্রহণযোগ্য, আর দুইটাই সৎ উত্তর:
             *   ৩০২ — সারিটা বসেছে
             *   ৪২২ — ফর্ম বলে দিয়েছে কোন ঘরটা লাগবে
             *
             * ব্যবহারকারীর দিক থেকে দুইটাই ব্যবহারযোগ্য পর্দা। ৫০০
             * একমাত্র উত্তর যেটা তাঁকে কিছুই বলে না।
             */
            if ($response->getStatusCode() >= 500) {
                $broken[] = $kind;
            }
        }

        $this->assertSame([], $broken, implode("\n", [
            'এই তালিকাগুলোয় শুধু নাম লিখে Save চাপলে সার্ভার ভেঙে পড়ে:',
            implode(' · ', $broken),
            '',
            'ঘরটা যদি সত্যিই লাগে, ঘোষণায় `rules => [required]` বসান —',
            'তখন ব্যবহারকারী ৪২২ পাবেন আর জানবেন কী বাদ পড়েছে।',
            'না লাগলে মাইগ্রেশনে কলামটা `nullable` করুন।',
            'দুইটার একটা বেছে নিতেই হবে; দুই জায়গায় দুই কথা বলা যাবে না।',
        ]));
    }

    /**
     * যে ঘর সত্যিই লাগে, তার অভাবটা নামসহ বলা হয়।
     *
     * ── কেন এটা আলাদা করে দেখা ──────────────────────────────────────
     * উপরের পরীক্ষাটা কেবল "৫০০ নয়" বলে। একটা তালিকা যদি নিঃশব্দে
     * সারিটা বসিয়ে দিত অসম্পূর্ণ অবস্থায়, ওটাও পাস করত — আর তখন
     * খাত ছাড়া একটা পেমেন্ট পদ্ধতি খাতায় বসে থাকত যেটা POS ব্যবহার
     * করতে গেলে ভাঙত, অনেক পরে, অন্য কারও হাতে।
     */
    public function test_the_field_that_is_really_needed_says_so_by_name(): void
    {
        $this->post('/master-data/payment-methods', ['name_en' => 'bKash'])
            ->assertSessionHasErrors('account_id');
    }

    /**
     * আর ঘরটা দিলে সারিটা সত্যিই বসে।
     *
     * বাধ্যতামূলক করাটা যেন কেবল একটা দরজা বন্ধ করা না হয় — যে পথে
     * কাজটা হওয়ার কথা সেটা খোলা আছে কি না, সেটাও দেখা।
     */
    public function test_with_the_account_given_the_row_is_written(): void
    {
        /*
         * টিলটা আগে — কারণ নতুন কোম্পানিতে টাকার কোনো খাতই থাকে না।
         *
         * ── এটাই দ্বিতীয় আবিষ্কার ───────────────────────────────────
         * প্রথমে `%Cash%` খুঁজে নেওয়া হয়েছিল, আর পরীক্ষা লাল হলো:
         * "Cash in Transit" (১১০৩) নামে মিলেছিল কিন্তু ওটা টাকার খাত
         * নয়। ঠিক করতে গিয়ে বেরোল আসল কথাটা — **বসানো ছকে টাকার
         * কোনো খাতই নেই**। টিল বা ব্যাংক হিসাব যেদিন প্রথম বসে,
         * সেদিনই `is_cash`/`is_bank` পতাকা ওঠে।
         *
         * অর্থাৎ ঘরটা বাধ্যতামূলক করার পর একেবারে নতুন কোম্পানিতে
         * ড্রপডাউনটা খালি থাকত। ফর্ম এখন সেটা বলে দেয়
         * (`nothing_to_choose_yet`), আর অ্যাপ নিজেই টিলটা বসায় যেদিন
         * প্রথম টাকা নড়ে ([[CashTillService::ensurePrimaryTill()]])।
         * পরীক্ষাটাও তাই সেই পথেই যায়।
         */
        app(CashTillService::class)->ensurePrimaryTill();

        $cash = Account::query()->money()->postable()->active()->firstOrFail();

        $this->post('/master-data/payment-methods', [
            'name_en' => 'bKash',
            'account_id' => $cash->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('mdm_payment_methods', [
            'name_en' => 'bKash',
            'account_id' => $cash->id,
        ]);
    }
}
