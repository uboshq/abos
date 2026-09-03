<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একদম নতুন কোম্পানিতে টাকা আদায় করা যায় তো?
 *
 * ── কোন ঘটনা থেকে এই ফাইলটা এসেছে ─────────────────────────────────────
 * ৩ সেপ্টেম্বর ২০২৬-এ লাইভে একটা **খালি** কোম্পানিতে (FMART) হাতে-কলমে
 * পুরো চক্র চালানো হয়েছিল — ঠিক যেভাবে একজন ক্রেতা প্রথম দিন চালাবেন।
 * পণ্য বসল, বিল হলো, ছাপাও হলো। তারপর আদায়ের পর্দায় গিয়ে সব থেমে গেল।
 *
 *     "টাকা কোথায় ঢুকল" ঘরে তিনটা বিকল্প:
 *       ১১০১ হাতে নগদ · ১১০২ ব্যাংক · ১১০৫ মোবাইল ব্যাংকিং
 *     তিনটাই চেষ্টা করা হলো, তিনটাই প্রত্যাখ্যাত:
 *       "একটা মাথা, খাত নয় — নিচের একটা খাত বাছুন।"
 *
 * ⚠️ **নিচে কিছুই ছিল না।** ব্যবস্থাটা এমন জিনিস বাছতে বলত যা তালিকায়
 * নেই, আর ক্রেতা ভাবতেন পর্দাটাই ভাঙা।
 *
 * ── কেন সুইট এটা ধরেনি ────────────────────────────────────────────────
 * প্রতিটা আদায়-টেস্ট নিজে একটা খাত বা টিল বানিয়ে নিত, তারপর আদায় করত।
 * অর্থাৎ **টেস্টগুলো ঠিক সেই জিনিসটা নিজেরাই বসিয়ে নিত যেটার অভাবেই
 * ক্রেতা আটকাতেন।** সবুজ সুইট, আর লাইভে দরজা বন্ধ।
 *
 * তাই এই ফাইলটা **কিছুই বানায় না** — কেবল নতুন কোম্পানিটা তৈরি করে, আর
 * জিজ্ঞেস করে: এখন কি টাকা নেওয়ার একটা জায়গা আছে?
 *
 * ── কেন এটা "১১টা শিল্পের পণ্য"-এর প্রশ্ন ─────────────────────────────
 * ক্রেতা নিজে নিজে ব্যবস্থাটা দাঁড় করাতে পারবেন কিনা — সেটাই বিক্রির
 * পণ্যের আসল প্রশ্ন। যে দোকান টাকা নেয় না এমন দোকান নেই, তাই একটা নগদ
 * কাউন্টার অনুমান নয়, প্রতিটা ব্যবসার সত্য। ব্যাংক ও MFS আলাদা কথা —
 * ওগুলোর নম্বর কেবল ক্রেতাই জানেন, তাই ওগুলো বসানো হয় না।
 */
class ANewCompanyCanTakeMoneyOnDayOneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * প্রথম দরজা দিয়ে একটা ব্যবসা দাঁড় করানো — ঠিক যেভাবে ক্রেতা করেন।
     */
    private function setUpABrandNewBusiness(): Company
    {
        $this->post('/setup', [
            'name' => 'Al-Amin Shuvo',
            'email' => 'first@example.test',
            'password' => 'FirstOwner2026',
            'password_confirmation' => 'FirstOwner2026',
            'company_name' => 'Trade Depot',
            'branch_name' => 'Head Office',
        ])->assertRedirect(route('dashboard'));

        return Company::query()->firstOrFail();
    }

    /**
     * ⭐ আসল পাহারা।
     *
     * নিয়মটা [[CollectionService::assertMoneyAccount()]]-এ লেখা, আর এখানে
     * হুবহু সেটাই যাচাই করা হয় — টেস্টের নিজের কোনো আলাদা সংজ্ঞা নেই:
     *
     *   • খাতটা মাথা হতে পারবে না  (`is_group` মিথ্যা)
     *   • খাতটার বাবা টাকার তিন মাথার একটা হতে হবে
     *
     * এই দুইটা একসাথে যে সারিটা মানে, ড্রপডাউনে ঠিক সেটাই বাছা যায়।
     */
    public function test_a_brand_new_company_has_somewhere_to_receive_money(): void
    {
        $company = $this->setUpABrandNewBusiness();

        CompanyContext::forCompany($company->id, function (): void {
            $heads = Account::query()
                ->whereIn('code', StandardChart::MONEY_PARENTS)
                ->pluck('id');

            $postable = Account::query()
                ->where('is_group', false)
                ->whereIn('parent_id', $heads)
                ->get();

            $this->assertNotEmpty(
                $postable,
                'নতুন কোম্পানিতে টাকা নেওয়ার একটাও খাত নেই। '
                .'আদায়ের পর্দা তিনটা মাথা দেখাবে আর তিনটাই ফিরিয়ে দেবে — '
                .'ক্রেতা মাল বেচতে পারবেন, টাকা নিতে পারবেন না। '
                .'দেখুন: Accounts/module.php-এর `provisions`-এ CashTillService আছে তো?'
            );
        });
    }

    /**
     * খাতটা সত্যিই একটা কাউন্টারের, আর কাউন্টারটা পর্দায় দেখা যায়।
     *
     * ── কেন এটা আলাদা টেস্ট ───────────────────────────────────────────
     * উপরেরটা কেবল একটা **খাত** খোঁজে। কেউ চাইলে খাতটা সরাসরি ছকে বসিয়ে
     * ওটা সবুজ করে ফেলতে পারত — আর তখন ব্যালেন্স মিলত, কিন্তু "নগদ
     * কাউন্টার" পর্দায় কিছুই থাকত না, নগদ গণনাও করা যেত না।
     *
     * টিল আর তার খাত একসাথে জন্মায় ([[CashTillService::create()]]), আর
     * এই টেস্টটা সেটাই ধরে রাখে।
     */
    public function test_the_money_lands_in_a_counter_people_can_see_and_count(): void
    {
        $company = $this->setUpABrandNewBusiness();

        CompanyContext::forCompany($company->id, function (): void {
            $till = CashTill::query()->first();

            $this->assertNotNull($till, 'নতুন কোম্পানিতে একটাও নগদ কাউন্টার নেই।');
            $this->assertTrue((bool) $till->is_primary, 'কাউন্টারটা প্রধান নয় — জমা কোথায় যাবে তা নির্ধারিত থাকে না।');

            $account = Account::query()->findOrFail($till->account_id);
            $cashHead = Account::query()->where('code', StandardChart::CASH_IN_HAND)->firstOrFail();

            $this->assertSame($cashHead->id, $account->parent_id, 'কাউন্টারের খাতটা "হাতে নগদ"-এর নিচে নেই।');
            $this->assertFalse((bool) $account->is_group, 'কাউন্টারের খাতটা নিজেই একটা মাথা — ওখানে টাকা বসে না।');
        });
    }

    /**
     * ⚠️ আর এটাই সেই টেস্ট যেটা ভুল সারাই ধরবে।
     *
     * সহজ কিন্তু ভুল সমাধান ছিল: তিনটা মাথাকে `is_group = false` করে দেওয়া,
     * তাতে ড্রপডাউনের বিকল্পগুলো "কাজ করত"। কিন্তু তখন টাকা বসত মাথায়,
     * আর **মাথা আর তার সন্তানদের যোগফল দুইবার গোনা হত** — রেওয়ামিল
     * নিঃশব্দে ভুল হয়ে যেত।
     *
     * তাই মাথাগুলো মাথাই থাকবে, এটা এখানে বাঁধা।
     */
    public function test_the_three_money_heads_stay_heads(): void
    {
        $company = $this->setUpABrandNewBusiness();

        CompanyContext::forCompany($company->id, function (): void {
            foreach (StandardChart::MONEY_PARENTS as $code) {
                $head = Account::query()->where('code', $code)->firstOrFail();

                $this->assertTrue(
                    (bool) $head->is_group,
                    "খাত {$code} আর মাথা নয়। ওখানে সরাসরি টাকা বসালে যোগফল দুইবার গোনা হবে।"
                );
            }
        });
    }
}
