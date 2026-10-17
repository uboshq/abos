<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Models\User;
use App\Modules\Executive\Http\Controllers\ExecutiveApiController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ⛔ কোনো কোম্পানি না থাকলে ফোন "অনুমতি নেই" (••••) দেখাত — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ খালি যোগফল ঢাকা ঘর হয়ে `"hidden"` যেত। এখন ঘর খালি (null), আর সঙ্গে পর্দার নিজের "কোনো কোম্পানি নেই" কথা।
 * ⓘ দরজার চাবি এখানে মাপা নয় (চাবি থাকলে কোম্পানিও থাকে) — তাই নিয়ন্ত্রক সরাসরি ডাকা।
 */
final class ThePhoneSaysNoCompanyNotNoPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_no_company_the_phone_gets_the_no_company_message_not_hidden_figures(): void
    {
        $this->seed(DemoSeeder::class);

        $nobody = User::factory()->create(['is_active' => true]);
        $this->actingAs($nobody);

        $request = Request::create('/api/v1/executive/today', 'GET');
        $request->setUserResolver(fn () => $nobody);

        $json = app(ExecutiveApiController::class)->today($request)->getData(true);

        $this->assertSame([], $json['companies'], 'প্রস্তুতিটাই ভুল — মানুষটার কোম্পানি আছে।');
        $this->assertSame(__('executive::today.no_company'), $json['message'] ?? null, '⛔ "কোনো কোম্পানি নেই" কথাটা ফোনে যায়নি।');

        foreach ($json['figures'] as $figure) {
            $this->assertNotSame(ExecutiveApiController::HIDDEN, $figure['value'], "⛔ '{$figure['key']}' ঘরটা \"অনুমতি নেই\" দেখাচ্ছে, অথচ কোম্পানিই নেই।");
        }
    }
}
