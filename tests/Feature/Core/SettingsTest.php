<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Control Panel — নিয়ম ৭।
 *
 * সুইচগুলোর সংজ্ঞা মডিউলের module.php-তে; এখানে যাচাই হচ্ছে সেই সংজ্ঞা
 * ঠিকভাবে পড়া হচ্ছে, কোম্পানিভেদে আলাদা মান রাখা যাচ্ছে, আর অচেনা কী
 * চুপচাপ null হয়ে যাচ্ছে না।
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $settings;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);

        $this->alpha = Company::create(['code' => 'A', 'name_en' => 'Alpha']);
        $this->beta = Company::create(['code' => 'B', 'name_en' => 'Beta']);

        CompanyContext::set($this->alpha->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_settings_are_declared_by_the_modules_themselves(): void
    {
        $definitions = $this->settings->definitions();

        // Customer মডিউলের module.php-তে ঘোষিত — Control Panel-এ আলাদা
        // করে কিছু লিখতে হয়নি।
        $this->assertArrayHasKey('customer.credit_limit_enabled', $definitions);
        $this->assertSame('customer', $definitions['customer.credit_limit_enabled']['module']);
        $this->assertSame('boolean', $definitions['customer.credit_limit_enabled']['type']);
    }

    public function test_an_untouched_setting_falls_back_to_the_modules_default(): void
    {
        $this->assertTrue($this->settings->enabled('customer.credit_limit_enabled'));
        $this->assertFalse($this->settings->enabled('customer.require_bn_name'));
        $this->assertSame(7, $this->settings->get('accounts.backdate_days'));
    }

    public function test_a_company_can_override_without_affecting_another(): void
    {
        $this->settings->set('accounts.backdate_days', 30);
        $this->assertSame(30, $this->settings->get('accounts.backdate_days'));

        CompanyContext::set($this->beta->id);
        $this->settings->flush();

        // বিটা কিছু বদলায়নি, তাই সে ডিফল্টেই থাকে।
        $this->assertSame(7, $this->settings->get('accounts.backdate_days'));
    }

    public function test_resetting_returns_to_the_default(): void
    {
        $this->settings->set('customer.credit_limit_enabled', false);
        $this->assertFalse($this->settings->enabled('customer.credit_limit_enabled'));

        $this->settings->reset('customer.credit_limit_enabled');
        $this->assertTrue($this->settings->enabled('customer.credit_limit_enabled'));
    }

    public function test_an_unknown_key_is_an_error_not_a_silent_false(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Unknown setting 'customer.credt_limit'/");

        // টাইপো। null ফেরালে সুইচটা "বন্ধ" ধরা হত আর ফিচারটা নীরবে হারিয়ে যেত।
        $this->settings->get('customer.credt_limit');
    }

    public function test_an_explicit_fallback_is_allowed_for_a_genuinely_optional_key(): void
    {
        $this->assertSame('nothing', $this->settings->get('some.future.key', 'nothing'));
    }

    public function test_entry_and_print_switches_are_separate_groups(): void
    {
        $entry = $this->settings->group('customer', 'entry');
        $print = $this->settings->group('customer', 'print');

        // সেকশন ১৫.২৪ — এন্ট্রির ফিল্ড আর প্রিন্টের ফিল্ড আলাদা ভাগে।
        $this->assertArrayHasKey('customer.credit_limit_enabled', $entry);
        $this->assertArrayNotHasKey('customer.credit_limit_enabled', $print);
        $this->assertArrayHasKey('customer.show_photo_on_print', $print);
    }

    public function test_setting_an_undeclared_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settings->set('made.up.key', true);
    }
}
