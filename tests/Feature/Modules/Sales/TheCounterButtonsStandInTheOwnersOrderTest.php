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
 * কাউন্টারের বোতাম মালিকের ক্রমে — মালিক, ৪ অক্টোবর ২০২৬ (ছবিসহ):
 *   সারি ১: টাকা নিন, গাড়ি ও ভাড়া, মাল কীভাবে নেবে, ফেরত নিন
 *   সারি ২: খসড়া রাখুন, খসড়া খুলুন, আবার ছাপুন, বিল বাতিল
 *   সারি ৩: নিশ্চিত করুন
 * আর "দাম দেখুন" ক্রেতার ঘরের নিচে, "✎ মন্তব্য"-এর লাইনের একদম ডানে।
 */
final class TheCounterButtonsStandInTheOwnersOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_buttons_stand_in_the_owners_order(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $html = (string) $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->get(route('sales.direct.create'))->assertOk()->getContent();

        $at = function (string $needle) use ($html): int {
            $pos = strpos($html, $needle);
            $this->assertNotFalse($pos, "⛔ পাতায় নেই: {$needle}");

            return (int) $pos;
        };

        // ⓘ টাকা নিন আর গাড়ি-ভাড়া সুইচের পেছনে — যেগুলো আছে কেবল সেগুলোর ক্রম মাপা হয়
        $order = array_values(array_filter(
            ['deposit', 'transport', 'delivery', 'return', 'save_draft', 'drafts', 'reprint', 'cancel'],
            fn (string $b) => str_contains($html, 'data-counter-button="'.$b.'"'),
        ));
        $this->assertContains('delivery', $order, 'প্রস্তুতিটাই ভুল — বোতামগুলো পাতায় নেই।');

        $positions = array_map(fn (string $b) => $at('data-counter-button="'.$b.'"'), $order);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '⛔ বোতামগুলো মালিকের ক্রমে নেই: '.implode(', ', $order));

        // ⓘ ছয়টা বাধ্যতামূলক বোতাম সবসময় থাকে, আর "খসড়া রাখুন" দ্বিতীয় সারির প্রথম
        foreach (['delivery', 'return', 'save_draft', 'drafts', 'reprint', 'cancel'] as $must) {
            $this->assertContains($must, $order, "⛔ বোতাম নেই: {$must}");
        }

        $this->assertGreaterThan($at('data-counter-button="cancel"'), $at('x-ref="confirm"'), '⛔ "নিশ্চিত করুন" শেষ সারিতে নেই।');

        $memo = $at('data-memo');
        $price = $at('data-counter-button="price"');
        $this->assertGreaterThan($memo, $price, '⛔ "দাম দেখুন" মন্তব্যের লাইনে, তার ডানে নেই।');
        $this->assertLessThan($at('name="customer_id"'), $price, '⛔ "দাম দেখুন" ক্রেতার ঘরের নিচের লাইনে নেই।');
        $this->assertLessThan($at('data-counter-buttons'), $price, '⛔ "দাম দেখুন" এখনো আট বোতামের সারিতে।');
    }
}
