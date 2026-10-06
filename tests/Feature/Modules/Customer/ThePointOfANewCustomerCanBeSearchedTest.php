<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নতুন গ্রাহকের পয়েন্ট খোঁজা যায় — মালিক, ৬ অক্টোবর ২০২৬: *"নতুন গ্রাহক পেজে পয়েন্ট * সার্চ বক্স দাও"*।
 *
 * দাবি:
 *  · পয়েন্টের ঘর এখন খোঁজার পিকার, লম্বা `<select>` নয়; খোঁজার ঘরে পয়েন্টের নিজের লেখা।
 *  · ফর্ম আগের নামেই (`location_id`) মান পাঠায়, আর প্রতিটা পয়েন্ট কোড আর দুই ভাষার নামে খোঁজা যায়।
 *  · পরিবেশকের জন্য তারাটা আগের মতো ধরন দেখে ওঠে-নামে।
 */
final class ThePointOfANewCustomerCanBeSearchedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_point_is_a_search_box_that_still_sends_location_id(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $point = Location::query()->atLevel([Location::POINT])->active()->firstOrFail();

        $html = $this->get(route('customer.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<select id="location_id"', $html, '⛔ পয়েন্ট এখনো লম্বা সাধারণ তালিকা।');
        $this->assertStringContainsString('<input type="hidden" name="location_id"', $html, '⛔ ফর্ম আর location_id পাঠায় না।');
        $this->assertStringContainsString(e(__('customer::message.point_search')), $html, '⛔ খোঁজার ঘরে পয়েন্টের লেখা নেই।');
        $this->assertStringNotContainsString(e(__('accounts::field.party_search')), $html, '⛔ পয়েন্টের খোঁজে পক্ষের লেখা।');

        // ⓘ পিকারের তালিকা x-data-তে — পয়েন্টের কোড ছোট লাইনে আর খোঁজার লেখায়
        $this->assertStringContainsString((string) $point->code, $html, '⛔ পয়েন্ট তালিকায় নেই।');
        $this->assertStringContainsString(mb_strtolower((string) $point->code), $html, '⛔ পয়েন্ট কোডে খোঁজা যায় না।');

        // ⓘ পরিবেশক হলে তারা — শর্তটা আগের মতোই ধরন দেখে
        $this->assertMatchesRegularExpression('/id="location_id-label".*?x-show="partyType === /s', $html, '⛔ পরিবেশকের তারাটা হারিয়েছে।');
    }

    /**
     * ⭐ "এটা আলাদা প্রতিষ্ঠান" ঘরটা ফর্মে আছে — মালিক, ৬ অক্টোবর ২০২৬: *"ঘরটা সেটাই তো নাই"*।
     * ⓘ বার্তা বলত "টিক দিয়ে আবার সংরক্ষণ করুন", অথচ ঘরটা আঁকা হত না — নামে মিল পেলে মানুষ আটকে থাকতেন।
     */
    public function test_a_name_match_shows_the_box_and_ticking_it_saves(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $form = ['name_en' => 'Twin Probe Store', 'opening_balance' => '0'];

        // ⓘ প্রথমবার ঘরটা নেই — সব সময় দেখালে না পড়েই টিক পড়ত
        $this->assertStringNotContainsString('data-allow-duplicate', $this->get(route('customer.create'))->getContent());

        $this->post(route('customer.store'), $form)->assertRedirect();

        $this->from(route('customer.create'))->post(route('customer.store'), $form)
            ->assertRedirect(route('customer.create'))->assertSessionHasErrors('name_en');

        // ⓘ ঘরটা নিজে, ভুলের থলে হাতে বসিয়ে — একই পরীক্ষায় দ্বিতীয় অনুরোধে ভিউ আগের ফাঁকা `$errors`-ই ধরে রাখে
        $match = session('errors')->first('name_en');
        $this->assertStringStartsWith(__('core.duplicate.name_matches'), $match);
        view()->share('errors', (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['name_en' => [$match]])));
        $this->assertStringContainsString('name="allow_duplicate"', \Illuminate\Support\Facades\Blade::render('<x-ui.duplicate-confirm />'), '⛔ নামে মিলের পরে "এটা আলাদা প্রতিষ্ঠান" ঘর নেই।');
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->assertStringNotContainsString('name="allow_duplicate"', \Illuminate\Support\Facades\Blade::render('<x-ui.duplicate-confirm />'));
        foreach (['customer', 'supplier'] as $module) {
            $this->assertStringContainsString('<x-ui.duplicate-confirm />', file_get_contents(base_path("app/Modules/".ucfirst($module)."/Resources/views/form.blade.php")), "⛔ {$module}-এর ফর্মে ঘরটা বসানো নেই।");
        }

        $this->post(route('customer.store'), $form + ['allow_duplicate' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, \App\Modules\Customer\Models\Customer::query()->where('name_en', 'Twin Probe Store')->count());
    }

    /**
     * ⭐ খোলা ব্যালেন্স = অঙ্ক + দিক — মালিক, ৬ অক্টোবর ২০২৬: *"আন্তর্জাতিক মান অনুযায়ী সাজিয়ে দাও"*।
     * ⓘ "গ্রাহক পাবে (Cr)" বাছলে খাতায় Cr (ঋণাত্মক বাকি), "দেবে (Dr)" বাছলে Dr; দিক না পাঠালে চিহ্ন যেমন এসেছে।
     */
    public function test_the_opening_side_sets_dr_or_cr(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = $this->get(route('customer.create'))->getContent();
        $this->assertStringContainsString('name="opening_side"', $html, '⛔ খোলা ব্যালেন্সের দিক বাছার ঘর নেই।');
        $this->assertStringContainsString(e(__('customer::field.opening_side_cr')), $html);

        foreach ([['Side Cr Probe', '1500', 'cr', '-1500'], ['Side Dr Probe', '1500', 'dr', '1500'], ['Side Dr Minus Probe', '-700', 'dr', '700'], ['No Side Probe', '-300', null, '-300']] as [$name, $amount, $side, $want]) {
            $form = ['name_en' => $name, 'opening_balance' => $amount, 'opening_date' => now()->toDateString()] + ($side ? ['opening_side' => $side] : []);
            $this->post(route('customer.store'), $form)->assertRedirect()->assertSessionHasNoErrors();

            $got = (string) \App\Modules\Customer\Models\Customer::query()->where('name_en', $name)->firstOrFail()->outstanding();
            $this->assertSame(0, bccomp($got, $want, 4), "⛔ {$name}: খাতায় {$got}, চাই {$want}।");
        }
    }
}
