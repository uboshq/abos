<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ উপরের বারের নিচে খালি ধূসর পট্টি — মালিকের ড্যাশবোর্ডের ছবি (লাইভ-হটফিক্স, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ হোমে কোনো মডিউল নেই, তাই মডিউল-বারে ট্যাব নেই, আর পর্দাটা নিজের বোতামও পাঠায় না — তবু বারটা আঁকা হত: ফোনে সবসময়, বড়
 * পর্দায় মেনু গুটালে। ⭐ এখন দেখানোর কিছু না থাকলে বারটা DOM-এই নেই (কেবল লুকানো নয়), পাতার মাথা উপরের বারের ঠিক নিচে।
 * আর যেখানে ট্যাব আছে (বিক্রয়ের তালিকা), বারটা থাকে।
 */
final class AnEmptyModuleBarIsNotDrawnTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_has_no_module_bar_and_a_sales_list_keeps_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $home = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-module-bar', $home, '⛔ হোমে দেখানোর কিছু নেই, তবু মডিউল-বার আঁকা হলো — উপরের বারের নিচে খালি পট্টি।');

        $list = (string) $this->actingAs($owner)->get(route('sales.invoice.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-module-bar', $list, '⛔ বিক্রয়ের তালিকায় ট্যাব আছে, অথচ মডিউল-বার নেই।');
        $bar = substr($list, (int) strpos($list, 'data-module-bar'), 20000);
        $this->assertStringContainsString('href="'.e(route('sales.invoice.index')).'"', $bar, 'প্রস্তুতিটাই ভুল — বারে বিক্রয়ের ট্যাব নেই।');
    }
}
