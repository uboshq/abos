<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Support\InvoiceDesigns;
use App\Modules\Sales\Support\InvoicePrintLook;
use App\Modules\SystemAdmin\Http\Controllers\InvoiceInfoController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Set Invoice Information" — প্রতিটা সুইচ কাগজ বদলায়, আর পাতাটা কেবল কন্ট্রোল প্যানেলের চাবিতে খোলে।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Set Invoice Information ei name alada tab koro"* → *"Print control er vitotre korte paro"*।
 *
 * ── ⚠️ কেন প্রতিটা সুইচ আলাদা করে ─────────────────────────────────────
 * ⛔ একটা সুইচ যেটা সংরক্ষিত হয় কিন্তু ছাঁচ পড়ে না, পর্দায় একদম ঠিক দেখায় — মালিক টিক তুলে
 * সংরক্ষণ করেন, "সংরক্ষিত" দেখেন, আর কাগজ আগের মতোই ছাপে। ⓘ তাই প্রতিটা সুইচের একটা চিহ্ন,
 * আর তালিকাটা [[InvoicePrintLook::SHOWS]]-এর সাথে মেলানো: নতুন সুইচ এলে চিহ্ন না দেওয়া
 * পর্যন্ত এই পরীক্ষা লাল।
 */
final class SetInvoiceInformationChangesThePaperTest extends TestCase
{
    use RefreshDatabase;
    // ⓘ এই পরীক্ষার প্রশ্ন ডিলারের দেয়াল নয় — ডেমোর বিক্রয়কর্মীর চিহ্ন তোলা (⛔১৬, ৪ অক্টোবর ২০২৬)
    use \Tests\Concerns\TakesTheDealerWallOffTheDemoSalesman;

    /** সুইচ → চালু থাকলে নমুনা কাগজে যে চিহ্ন থাকে */
    private const MARKS = [
        'bin' => 'data-tax-ids',
        'invoice_type' => 'data-invoice-type',
        'duplicate' => 'data-duplicate',
        'order_no' => 'data-order-no',
        'transport' => 'data-transport',
        'free' => 'data-col-free',
        'total_qty' => 'data-col-total-qty',
        'grand_total_row' => 'data-grand-row',
        'previous_due' => 'data-previous-due',
        'amount_words' => 'data-words',
        'deposits' => 'data-deposits',
        'qr' => 'data-scan-qr',
        'product_code' => 'data-line-code',
        'lot' => 'data-line-lot',
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->takeTheDealerWallOffTheDemoSalesman();

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        /* ⓘ BIN-এর সুইচ দেখাতে হলে BIN থাকতে হয় — নাহলে লাইনটা এমনিতেই নেই */
        $this->company->update(['bin' => '000111222-0101']);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_every_switch_has_a_mark_on_the_paper(): void
    {
        $this->assertSame(InvoicePrintLook::SHOWS, array_keys(self::MARKS),
            'একটা দেখানো/লুকানোর সুইচের কাগজে কোনো চিহ্ন নেই — এই পরীক্ষা তাকে মাপতে পারে না।');
    }

    public function test_each_switch_turned_off_takes_its_part_off_the_paper(): void
    {
        /* ⓘ আগে সব সুইচ চালু — পণ্যের কোড ডিফল্টে বন্ধ (মালিক, ১ অক্টোবর ২০২৬), তাই ডিফল্ট কাগজে ওর চিহ্ন থাকে না */
        $this->put(route('system_admin.print_control.invoice_info.update'), [
            'settings' => $this->everyShowOnExcept(null),
        ])->assertRedirect();

        $on = $this->get(route('sales.invoice_sample'))->assertOk()->getContent();

        foreach (self::MARKS as $switch => $mark) {
            $this->assertStringContainsString($mark, $on, "চালু অবস্থায় '{$switch}' কাগজে নেই।");
        }

        foreach (self::MARKS as $switch => $mark) {
            $this->put(route('system_admin.print_control.invoice_info.update'), [
                'settings' => $this->everyShowOnExcept($switch),
            ])->assertRedirect();

            $off = $this->get(route('sales.invoice_sample'))->assertOk()->getContent();

            $this->assertStringNotContainsString($mark, $off, "'{$switch}' বন্ধ করে সংরক্ষণের পরেও কাগজে আছে।");

            /* ⛔ কেবল নিজের অংশটা — বাকিরা থাকে */
            foreach (self::MARKS as $other => $otherMark) {
                if ($other !== $switch) {
                    $this->assertStringContainsString($otherMark, $off, "'{$switch}' বন্ধ করায় '{$other}'-ও চলে গেল।");
                }
            }
        }
    }

    /**
     * ⭐ প্রতিটা নকশা প্রতিটা সুইচ মানে — তালিকা থেকে হাঁটা ([[InvoiceDesigns::ALL]]), হাতে লেখা নয়।
     *
     * ⛔ নতুন নকশা একটা সুইচ না মানলে পর্দায় কিছুই ভুল দেখায় না: মালিক সুইচ বন্ধ করেন, ক্লাসিকে
     * মিলিয়ে দেখেন, আর যে কোম্পানি ঐ নকশা বেছেছে তার কাগজে জিনিসটা থেকেই যায়।
     */
    public function test_every_design_obeys_every_switch(): void
    {
        foreach (array_keys(InvoiceDesigns::ALL) as $design) {
            $url = route('sales.invoice_sample', ['design' => $design]);

            $this->put(route('system_admin.print_control.invoice_info.update'), [
                'settings' => $this->everyShowOnExcept(null),
            ]);
            $on = $this->get($url)->assertOk()->getContent();

            foreach (self::MARKS as $switch => $mark) {
                $this->assertStringContainsString($mark, $on, "{$design}: চালু অবস্থায় '{$switch}' কাগজে নেই।");
            }

            foreach (self::MARKS as $switch => $mark) {
                $this->put(route('system_admin.print_control.invoice_info.update'), [
                    'settings' => $this->everyShowOnExcept($switch),
                ]);

                $this->assertStringNotContainsString($mark, $this->get($url)->assertOk()->getContent(),
                    "{$design}: '{$switch}' বন্ধ করার পরেও কাগজে আছে।");
            }
        }
    }

    public function test_the_heading_and_the_signature_boxes_follow_the_page(): void
    {
        $this->put(route('system_admin.print_control.invoice_info.update'), [
            'settings' => [
                ...$this->everyShowOnExcept(null),
                'sales.print.header.name' => 'NAME ON THE PAPER',
                'sales.print.signature_count' => '4',
                'sales.print.signature.4' => 'Checked by',
                'sales.print.invoice_footnote' => 'OUR OWN LINE',
            ],
        ])->assertRedirect(route('system_admin.print_control.invoice_info'));

        $paper = $this->get(route('sales.invoice_sample'))->assertOk()->getContent();

        $this->assertStringContainsString('NAME ON THE PAPER', $paper);
        $this->assertStringContainsString('Checked by', $paper);
        $this->assertStringContainsString('OUR OWN LINE', $paper);
        $this->assertSame(4, substr_count($paper, 'data-signature'));

        /* ⓘ দুই ঘর বাছলে দুইটাই — আর খালি নাম মানে নমুনার নাম, ফাঁকা দাগ নয় */
        $this->put(route('system_admin.print_control.invoice_info.update'), [
            'settings' => [...$this->everyShowOnExcept(null), 'sales.print.signature_count' => '2'],
        ]);

        $paper = $this->get(route('sales.invoice_sample'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($paper, 'data-signature'));
        $this->assertStringContainsString((string) __('sales::print.classic.received_by', [], 'bn'), $paper);
    }

    public function test_a_blank_box_goes_back_to_the_company_profile(): void
    {
        $settings = app(SettingsService::class);

        $this->put(route('system_admin.print_control.invoice_info.update'), [
            'settings' => [...$this->everyShowOnExcept(null), 'sales.print.header.name' => 'FOR A WHILE'],
        ]);
        $this->assertSame('FOR A WHILE', $settings->get('sales.print.header.name'));

        $this->put(route('system_admin.print_control.invoice_info.update'), [
            'settings' => [...$this->everyShowOnExcept(null), 'sales.print.header.name' => '   '],
        ]);
        $settings->flush();
        $this->assertNull($settings->get('sales.print.header.name'));

        $paper = $this->get(route('sales.invoice_sample'))->assertOk()->getContent();
        $this->assertStringContainsString(e($this->company->name('en')), $paper);
    }

    public function test_a_count_outside_the_list_is_not_saved(): void
    {
        $this->put(route('system_admin.print_control.invoice_info.update'), [
            'settings' => [...$this->everyShowOnExcept(null), 'sales.print.signature_count' => '9'],
        ]);

        $this->assertSame('3', (string) app(SettingsService::class)->get('sales.print.signature_count'));
    }

    public function test_the_page_draws_every_declared_setting_and_the_general_screen_none(): void
    {
        $declared = array_keys(array_filter(
            app(SettingsService::class)->definitions(),
            /* ⓘ লোগো লেখার ঘর নয়, তোলার ঘর — নিচে আলাদা করে মাপা */
            fn (array $d) => ($d['group'] ?? null) === InvoiceInfoController::GROUP && ($d['part'] ?? null) !== 'logo',
        ));

        /* ⛔ নাম হাতে লিখে নয় — ঘোষণা থেকে; তবু খালি তালিকা যেন সবুজ না দেখায় */
        $this->assertGreaterThanOrEqual(20, count($declared));

        $page = $this->get(route('system_admin.print_control.invoice_info'))->assertOk()->getContent();
        $general = $this->get(route('system_admin.settings'))->assertOk()->getContent();

        foreach ($declared as $key) {
            $this->assertStringContainsString('settings['.$key.']', $page, "{$key} পাতায় নেই।");
            $this->assertStringNotContainsString('settings['.$key.']', $general, "{$key} সাধারণ সেটিংসেও আঁকা — দুই পর্দা এক সারিতে লিখত।");
        }

        /* ⭐ বিলের লোগো আলাদা তোলার ঘর — মালিক, ৩০ সেপ্টেম্বর ২০২৬: "INVOICE LOGO ALADA UPLOAD MUST" */
        $this->assertStringContainsString('name="invoice_logo"', $page);
        $this->assertStringContainsString('enctype="multipart/form-data"', $page);

        /* ⓘ ছাপার নিয়ন্ত্রণের কাগজের সারিতে ট্যাবটা আছে */
        $print = $this->get(route('system_admin.print_control'))->assertOk()->getContent();
        $this->assertStringContainsString(route('system_admin.print_control.invoice_info'), $print);
    }

    public function test_the_same_person_needs_the_control_panel_key(): void
    {
        $person = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($person);

        $this->get(route('system_admin.print_control.invoice_info'))->assertForbidden();
        $this->get(route('sales.invoice_sample'))->assertForbidden();
        $this->put(route('system_admin.print_control.invoice_info.update'), ['settings' => []])->assertForbidden();

        CompanyContext::forCompany($this->company->id,
            fn () => $person->givePermissionTo(Permission::findOrCreate('system_admin.settings.manage', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $person->refresh();

        $this->get(route('system_admin.print_control.invoice_info'))->assertOk();
        $this->get(route('sales.invoice_sample'))->assertOk();
    }

    /**
     * সব দেখানোর সুইচ চালু, একটা ছাড়া — চেকবক্স বন্ধ থাকলে ব্রাউজার ঘরটাই পাঠায় না।
     *
     * @return array<string, string>
     */
    private function everyShowOnExcept(?string $off): array
    {
        $sent = [];

        foreach (InvoicePrintLook::SHOWS as $what) {
            if ($what !== $off) {
                $sent["sales.print.show.{$what}"] = '1';
            }
        }

        return $sent;
    }
}
