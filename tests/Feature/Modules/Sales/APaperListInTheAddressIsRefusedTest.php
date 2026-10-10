<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CollectionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⛔ ঠিকানায় `?paper[]=` — কারণসহ ৪২২, ৫০০ নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৬; [[SalesPrintController::askedPaper()]])।
 *
 * ⓘ কাগজের মাপের ঘরে তালিকা এলে [[PaperSize::chosen()]] টাইপ-ত্রুটিতে ভাঙত, আর ছাপার পাতা সার্ভারের ভুল দেখাত।
 */
final class APaperListInTheAddressIsRefusedTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    public function test_a_list_for_the_paper_size_is_a_422_and_a_plain_size_still_prints(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $receipt = app(CollectionService::class)->create(['customer_id' => Customer::query()->orderBy('id')->value('id'),
            'trx_date' => now()->toDateString(), 'amount' => '500', 'instrument' => 'cash'], []);

        $this->get(route('sales.print.receipt', $receipt).'?paper[]=a4')->assertStatus(422);
        $this->get(route('sales.print.receipt', $receipt).'?paper=a5')->assertOk();
    }

    /**
     * ⛔ বাকি ছাপার দরজাগুলোও — ভাউচার, নোট, টাকা হস্তান্তর, মজুদ, ক্রয়, পেস্লিপ, কোটেশন, বছর-শেষ, হ্যান্ড লোন (১১ অক্টোবর ২০২৬,
     * PR #17 রিভিউ ⚠️১৩)। ⓘ সবাই এখন [[PaperSize::fromQuery()]] ডাকে; কেউ কাঁচা `$request->query('paper')` সরাসরি
     * [[PaperSize::chosen()]]-এ দিলে আবার ৫০০ — তাই কোড পড়ে দেখা, আর নিয়মটা নিজে মেপে দেখা।
     */
    public function test_no_print_door_hands_the_raw_query_to_chosen_and_the_shared_rule_says_422(): void
    {
        $raw = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/PaperSize::chosen\(\s*\$request->query\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $raw[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $raw, "⛔ এই দরজাগুলো ঠিকানার মাপ যাচাই না করেই নেয় — ?paper[]= দিলে ৫০০:\n".implode("\n", $raw));

        $this->assertSame('a5', \App\Core\Engines\Print\PaperSize::fromQuery(\Illuminate\Http\Request::create('/x?paper=a5')));
        $this->assertNull(\App\Core\Engines\Print\PaperSize::fromQuery(\Illuminate\Http\Request::create('/x')));

        try {
            \App\Core\Engines\Print\PaperSize::fromQuery(\Illuminate\Http\Request::create('/x?paper[]=a4'));
            $this->fail('⛔ তালিকা-মাপ ফিরল না — chosen()-এ গিয়ে ৫০০ হত।');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}
