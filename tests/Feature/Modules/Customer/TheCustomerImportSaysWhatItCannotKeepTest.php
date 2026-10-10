<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Imports\CustomerImporter;
use App\Modules\Customer\Imports\CustomerLimitImporter;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ গ্রাহকের ছোট তিনটা ফাঁক — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (গ্রাহক ১৭)।
 *
 * ⓘ (ক) তালিকার ছাঁকনি আর শুরুর বাকিতে "1e5" — ৫০০। (খ) সীমার ইমপোর্টে "1e5" হয়ে যেত ১৫। (গ) গ্রাহক ইমপোর্টে পরিশোধের শর্ত নীরবে হারাত।
 */
final class TheCustomerImportSaysWhatItCannotKeepTest extends TestCase
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

    public function test_one_e_five_is_a_reason_not_a_crash(): void
    {
        $this->get(route('customer.index', ['due_min' => '1e5']))->assertOk();
        $this->get(route('customer.index', ['advance_max' => '1e5']))->assertOk();

        $this->post(route('customer.store'), ['name_en' => 'E Five Shop', 'opening_balance' => '1e5', 'opening_date' => now()->toDateString()])
            ->assertSessionHasErrors('opening_balance');
        $this->post(route('customer.store'), ['name_en' => 'E Five Shop', 'credit_limit' => '1e5'])->assertSessionHasErrors('credit_limit');

        // ⛔ আসল ফর্ম সবসময় দিক পাঠায় — "পাবে" দিকে "1e5" আগে `Money::isZero()`-এ ৫০০ দিত (রিভিউ, ১০ অক্টোবর ২০২৬)
        foreach (['cr', 'dr'] as $side) {
            $this->post(route('customer.store'), ['name_en' => 'E Five Shop', 'opening_balance' => '1e5', 'opening_side' => $side, 'opening_date' => now()->toDateString()])
                ->assertSessionHasErrors('opening_balance');
        }
        $this->assertFalse(Customer::query()->where('name_en', 'E Five Shop')->exists());
    }

    public function test_the_limit_import_reads_numbers_honestly(): void
    {
        $code = (string) Customer::query()->value('code');
        $importer = app(CustomerLimitImporter::class);

        $this->assertNotSame([], $importer->check(['code' => $code, 'credit_limit' => '1e5']), '⛔ "1e5" চুপচাপ ১৫ হয়ে বসত');
        $this->assertNotSame([], $importer->check(['code' => $code, 'credit_limit' => '50 হাজার']), '⛔ "৫০ হাজার" চুপচাপ ৫০ হয়ে বসত');
        $this->assertSame([], $importer->check(['code' => $code, 'credit_limit' => '50,000 ৳']), 'কমা আর টাকার চিহ্ন চলে');
        $this->assertSame([], $importer->check(['code' => $code, 'credit_limit' => '25000.50']));
    }

    public function test_a_payment_term_in_the_customer_import_is_named_not_dropped(): void
    {
        $row = ['code' => '', 'name_en' => 'Term Shop', 'name_bn' => '', 'phone' => '', 'email' => '', 'address' => '', 'party_type' => '',
            'payment_term' => 'NET30', 'credit_limit' => '', 'credit_days' => '', 'opening_balance' => '', 'opening_date' => ''];

        $errors = app(CustomerImporter::class)->check($row);

        $this->assertContains(__('customer::import.payment_term_not_kept', ['value' => 'NET30']), $errors,
            '⛔ পরিশোধের শর্ত নীরবে হারাল: '.implode(' | ', $errors));
        $this->assertNotContains(__('customer::import.payment_term_not_kept', ['value' => '']), app(CustomerImporter::class)->check(['payment_term' => ''] + $row),
            'শর্ত খালি থাকলে এই কারণে থামে না');
    }

    /** ⛔ গ্রাহক আমদানিতেও "1e5" — শুরুর বাকি আর সীমায় কারণ, ভাঙা বা ১,০০,০০০ নয় (রিভিউ, ১০ অক্টোবর ২০২৬; [[CustomerImporter::check()]]) */
    public function test_the_customer_import_refuses_one_e_five_with_a_reason(): void
    {
        $row = ['code' => 'IMP-E5', 'name_en' => 'E5 Import', 'name_bn' => '', 'phone' => '', 'email' => '', 'address' => '', 'party_type' => '',
            'payment_term' => '', 'credit_limit' => '', 'credit_days' => '', 'opening_balance' => '', 'opening_date' => ''];

        foreach (['opening_balance', 'credit_limit'] as $column) {
            $this->assertContains(__('core.import.not_a_number', ['column' => $column]), app(CustomerImporter::class)->check([$column => '1e5'] + $row),
                '⛔ আমদানিতে "1e5" সংখ্যা হিসেবে পার হল: '.$column);
            $this->assertNotContains(__('core.import.not_a_number', ['column' => $column]), app(CustomerImporter::class)->check([$column => '1500.50'] + $row));
        }
    }
}
