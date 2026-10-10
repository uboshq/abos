<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ একটা কাগজ এক ভাষায় — মিশ্র নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৯; fe-র সিদ্ধান্ত, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ অডিট ধরেছিল বাংলা ব্যবহারকারীর বিলে হাতে লেখা ইংরেজি ("Closing balance", "This invoice")। PR #17 লেখাগুলো `__()`-তে নিল,
 * অথচ এই নকশাগুলোর বাকি সব লেখা জোর করে ইংরেজি — বাংলা পর্দায় বেরোত আধা-বাংলা আধা-ইংরেজি কাগজ ("Invoice & Account
 * Statement … সমাপনী জের … + বিল 1,200")।
 *
 * ⭐ সিদ্ধান্ত (মিশ্র কাগজ এড়াতে): ইংরেজি নকশা (acct_*, special_db) পুরো ইংরেজি, পাঠকের পর্দার ভাষা যাই হোক; বাংলা চাইলে বাংলা
 * নকশাগুলো আছে (mono_light_bn, bangla_heritage …), আর সেগুলোয় হাতে লেখা ইংরেজি নেই। অডিটের আসল উদ্দেশ্য — মিশ্র কাগজ না
 * বেরোনো — দুই দিক থেকেই মাপা।
 */
final class TheBengaliBillDesignsSpeakBengaliTest extends TestCase
{
    use RefreshDatabase;

    private const ENGLISH_DESIGNS = ['acct_card', 'acct_classic', 'special_db', 'acct_tiles', 'acct_sidebar_light', 'acct_t_account'];

    private const BENGALI_DESIGNS = ['mono_light_bn', 'bangla_heritage', 'world_standard_bn', 'mono_bold_classic_bn'];

    /** ⓘ অডিটের ধরা লেখাগুলো — নকশায় যেগুলো এখন ভাষা-ফাইল থেকে আসে */
    private const KEYS = ['closing_balance', 'previous_short', 'bill_short', 'this_invoice', 'owed_by_customer', 'paid_by_customer',
        'received_short', 'closing_short', 'previous_balance', 'received_today'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $user->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($user->fresh());
    }

    /** ⭐ বাংলা পর্দায় ইংরেজি নকশার কাগজে এই লেখাগুলোর একটাও বাংলায় নয় — আর ইংরেজিটা সত্যিই আছে। */
    public function test_an_english_design_stays_english_on_a_bengali_screen(): void
    {
        foreach (self::ENGLISH_DESIGNS as $design) {
            $paper = $this->sample($design);

            foreach (self::KEYS as $key) {
                $bn = (string) __('sales::print.'.$key, [], 'bn');
                $this->assertStringNotContainsString($bn, $paper, "⛔ ইংরেজি নকশায় ({$design}) বাংলা «{$bn}» — মিশ্র কাগজ।");
            }

            $this->assertStringNotContainsString((string) __('sales::settings.design.special_db', [], 'bn'), $paper, "⛔ {$design}: নকশার নাম বাংলায়।");
        }

        // ⓘ উল্টো দিক থেকে নোঙর — কাগজটা সত্যিই ঐ নকশা, আর লেখাটা ইংরেজিতে আছে (নইলে উপরের "নেই" কিছুই মাপত না)
        $this->assertStringContainsString((string) __('sales::print.closing_balance', [], 'en'), $this->sample('acct_card'));
        $this->assertStringContainsString((string) __('sales::print.this_invoice', [], 'en'), $this->sample('acct_tiles'));
    }

    /** ⭐ বাংলা নকশায় অডিটের ধরা হাতে লেখা ইংরেজি নেই। */
    public function test_a_bengali_design_carries_no_hard_written_english(): void
    {
        foreach (self::BENGALI_DESIGNS as $design) {
            $paper = $this->sample($design);

            foreach (['Closing balance', 'This invoice', 'Special for DB', 'Previous balance', 'Received today', 'owed by customer', 'paid by customer'] as $english) {
                $this->assertStringNotContainsString($english, $paper, "⛔ বাংলা নকশায় ({$design}) ইংরেজি «{$english}»।");
            }
        }
    }

    private function sample(string $design): string
    {
        return (string) $this->get(route('sales.invoice_sample', ['design' => $design]))->assertOk()->getContent();
    }
}
