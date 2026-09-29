<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Normalizer;
use Tests\TestCase;

/**
 * "প্রদেয় মুনাফা" (২১৯০) খুঁজে পাওয়া যেত না — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ নামটা লেখা ছিল একক-অক্ষরের য় (U+09DF) দিয়ে, যা NFC নয়; খোঁজার ঘরে
 * যা টাইপ হয় সেটা NFC হয়ে যায়, তাই "প্রদেয়" দিয়ে খাতটা কোনোদিন মিলত না।
 *
 * ⭐ একই সারি দুইবার: ভাঙা নাম বসিয়ে দেখা হয় NFC খোঁজায় মেলে না; মাইগ্রেশন
 * চলার পরে ঐ সারিই মেলে।
 */
final class ALiabilityNameCouldNotBeFoundBySearchingTest extends TestCase
{
    use RefreshDatabase;

    /** য় একক-অক্ষরে — লাইভে যেভাবে বসে গিয়েছিল */
    private const BROKEN = "\u{09AA}\u{09CD}\u{09B0}\u{09A6}\u{09C7}\u{09DF} \u{09AE}\u{09C1}\u{09A8}\u{09BE}\u{09AB}\u{09BE}";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_the_chart_itself_now_writes_the_name_in_nfc(): void
    {
        $names = Account::query()->withoutGlobalScopes()
            ->where('code', StandardChart::PROFIT_PAYABLE)->pluck('name_bn')->all();

        $this->assertNotEmpty($names, 'দৃশ্যটাই বানানো যায়নি — ২১৯০ খাত নেই।');

        foreach ($names as $name) {
            $this->assertTrue(Normalizer::isNormalized($name, Normalizer::FORM_C),
                '⛔ নতুন কোম্পানির ২১৯০-এর নাম এখনো NFC নয় — উৎসটাই ভাঙা।');
        }
    }

    public function test_the_migration_mends_a_name_already_stored_broken(): void
    {
        $this->assertFalse(Normalizer::isNormalized(self::BROKEN, Normalizer::FORM_C),
            'দৃশ্যটাই বানানো যায়নি — "ভাঙা" নামটা আসলে ভাঙা নয়।');

        $id = (int) Account::query()->withoutGlobalScopes()
            ->where('code', StandardChart::PROFIT_PAYABLE)->value('id');
        DB::table('accounts')->where('id', $id)->update(['name_bn' => self::BROKEN]);

        $typed = Normalizer::normalize('প্রদেয়', Normalizer::FORM_C);

        $this->assertFalse(DB::table('accounts')->where('id', $id)->where('name_bn', 'like', "%{$typed}%")->exists(),
            'ভাঙা নামও NFC খোঁজায় মিলে গেল — দাবিটা কিছু মাপছে না।');

        (require base_path('app/Modules/Accounts/Database/Migrations/2027_01_29_100000_a_liability_name_could_not_be_found_by_searching.php'))->up();

        $this->assertTrue(DB::table('accounts')->where('id', $id)->where('name_bn', 'like', "%{$typed}%")->exists(),
            '⛔ মাইগ্রেশনের পরেও "প্রদেয়" লিখে খাতটা পাওয়া যায় না।');
        $this->assertTrue(Normalizer::isNormalized((string) DB::table('accounts')->where('id', $id)->value('name_bn'), Normalizer::FORM_C));
    }
}
