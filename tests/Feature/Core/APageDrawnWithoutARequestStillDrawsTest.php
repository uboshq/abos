<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * ⛔ layout-সহ পাতা অনুরোধ ছাড়া আঁকলে ভাঙত — "Undefined variable $errors" (Mac, Finance, ২৫ অক্টোবর ২০২৬; fe)।
 *
 * <p>layout এখন সব পাতায় কারণের বাক্স বসায় ([[x-ui.errors]], errors-everywhere 2c026a303)। `$errors` কেবল ওয়েবের অনুরোধে ভাগ
 * হয় (ShareErrorsFromSession) — পাতা সরাসরি আঁকলে (দাবি, ছাপা, মেইল, PDF) নেই, আর বাক্সটা পুরো পাতা ভেঙে দিত।
 *
 * দাবি: অনুরোধ ছাড়া layout-এর পাতা আর বাক্স নিজে আঁকা যায়, বাক্সটা খালি থাকে; আর ভাগ করা থলে থাকলে কারণটা আগের মতোই দেখায়
 * (খালি থলের সারাই আসল কারণ লুকায় না)।
 */
final class APageDrawnWithoutARequestStillDrawsTest extends TestCase
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

    public function test_a_layout_page_drawn_directly_has_an_empty_errors_box(): void
    {
        $this->assertArrayNotHasKey('errors', view()->getShared(), 'প্রস্তুতিটাই ভুল — অনুরোধ ছাড়াও $errors ভাগ করা আছে, তাহলে কিছুই মাপে না');

        try {
            $html = view('finance::risk.index', ['menu' => app(MenuBuilder::class)->forUser(auth()->user()), 'risks' => []])->render();
            $box = Blade::render('<x-ui.errors />');
        } catch (\Throwable $e) {
            $this->fail('⛔ অনুরোধ ছাড়া layout-এর পাতা আঁকা গেল না — ছাপা, মেইল আর PDF ভাঙত: '.$e->getMessage());
        }

        $this->assertStringContainsString(__('finance::risk.all_clear'), $html, 'প্রস্তুতিটাই ভুল — পাতাটা নিজের লেখাই আঁকেনি');
        $this->assertStringNotContainsString('role="alert"', $box, '⛔ কোনো কারণ নেই, তবু খালি কারণের বাক্স আঁকা হলো');
    }

    public function test_a_shared_bag_still_shows_its_reason(): void
    {
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag(['posting' => ['খাতায় বসানো গেল না — মাস বন্ধ']])));

        try {
            $box = Blade::render('<x-ui.errors />');
        } finally {
            view()->share('errors', null);
        }

        $this->assertStringContainsString('খাতায় বসানো গেল না — মাস বন্ধ', $box, '⛔ খালি থলের সারাই আসল কারণও লুকাল');
    }
}
