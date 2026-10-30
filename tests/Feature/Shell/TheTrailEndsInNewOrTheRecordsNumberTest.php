<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Core\Support\PageTail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * ⭐ "কোথায় আছি"-র শেষে "› নতুন" — নতুন কাগজের পাতায়; সম্পাদনায় কাগজের নিজের নম্বর (রসিদ-পরিশোধ মিল-তালিকা #৬ আর #৫২,
 * নকশা পাতা ২ "হিসাব › আদায় › নতুন"; ২৫ অক্টোবর ২০২৬)। ⓘ একবারই, খোলসে ([[shell/page-path]], [[PageTail]]) — কোনো পাতা কিছু লেখে না।
 *
 * দাবি:
 *  ১. রসিদের নতুন পাতা: "হিসাব › আদায় › নতুন" — "নতুন" এই পাতা (`aria-current`), "আদায়" এখন তালিকায় ফেরার লিংক।
 *  ২. গ্রাহকের সম্পাদনা: শেষে গ্রাহকের নিজের কোড (CUS-…), "নতুন" নয়।
 *  ৩. তালিকার পাতায় শেষ অংশ নেই — আগের মতোই পাতার নাম শেষে।
 *  ৪. ইংরেজিতে "New"; নম্বরহীন কাগজের সম্পাদনায় "সম্পাদনা"; অন্য পাতায় কিছু নয়।
 */
final class TheTrailEndsInNewOrTheRecordsNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['ui' => 'navy'])->save();
    }

    /** ১ — নকশা পাতা ২-এর পথ */
    public function test_a_new_receipt_reads_accounts_collection_new(): void
    {
        $path = $this->trailOf(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]));

        $this->assertMatchesRegularExpression('/<span data-page-tail[^>]*aria-current="page"[^>]*>'.preg_quote(__('core.crumb.new'), '/').'<\/span>/u', $path,
            '⛔ নতুন রসিদের পথের শেষে "› নতুন" নেই, বা সেটা "এই পাতা" বলে চিহ্নিত নয়');
        $this->assertSame(1, substr_count($path, 'aria-current="page"'), '⛔ পথে "এই পাতা" একের বেশি — পাতার সারিও এখনো শেষ বলে চিহ্নিত');
        $this->assertMatchesRegularExpression('/<a [^>]*href="[^"]+"[^>]*>[^<]+<\/a>\s*<span[^>]*aria-hidden="true">›<\/span>\s*<span data-page-tail/u', $path,
            '⛔ "নতুন"-এর আগের অংশটা লিংক নয় — তালিকায় ফেরা যায় না');
    }

    /** ২ — সম্পাদনায় নম্বর */
    public function test_an_edit_page_ends_in_the_records_own_number(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->assertNotSame('', (string) $customer->code, 'প্রস্তুতিটাই ভুল — নমুনার গ্রাহকের কোড নেই');

        $path = $this->trailOf(route('customer.edit', $customer));

        $this->assertMatchesRegularExpression('/<span data-page-tail[^>]*>'.preg_quote(e($customer->code), '/').'<\/span>/u', $path,
            '⛔ সম্পাদনার পাতার পথের শেষে গ্রাহকের নিজের কোড নেই');
        $this->assertStringNotContainsString('>'.__('core.crumb.new').'<', $path, '⛔ সম্পাদনার পাতাকে পথ "নতুন" বলছে');
    }

    /** ৩ — তালিকায় শেষ অংশ নেই */
    public function test_a_list_page_has_no_tail(): void
    {
        $path = $this->trailOf(route('customer.index'));

        $this->assertStringNotContainsString('data-page-tail', $path, '⛔ তালিকার পাতাতেও পথের শেষে বাড়তি অংশ বসল');
        $this->assertStringContainsString('aria-current="page"', $path, '⛔ তালিকার পাতার নামটা আর "এই পাতা" নয়');
    }

    /** ৪ — দুই ভাষা, নম্বরহীন কাগজ, অন্য পাতা */
    public function test_the_tail_speaks_both_languages_and_falls_back_to_edit(): void
    {
        app()->setLocale('en');
        $this->assertSame('New', PageTail::of($this->route('things/create', 'things.create')), '⛔ ইংরেজিতে পথের শেষ "New" নয়');
        app()->setLocale('bn');
        $this->assertSame('নতুন', PageTail::of($this->route('things/create', 'things.create')), '⛔ বাংলায় পথের শেষ "নতুন" নয়');

        $edit = $this->route('things/{thing}/edit', 'things.edit');
        $edit->bind(Request::create('things/5/edit'));
        $edit->setParameter('thing', new class extends Model
        {
            protected $attributes = ['id' => 5, 'name' => 'Nameless'];
        });
        $this->assertSame('সম্পাদনা', PageTail::of($edit), '⛔ নম্বরহীন কাগজের সম্পাদনায় পথের শেষ "সম্পাদনা" নয়');

        $this->assertNull(PageTail::of($this->route('things/{thing}', 'things.show')), '⛔ দেখার পাতাতেও পথের শেষে বাড়তি অংশ');
        $this->assertNull(PageTail::of(null), '⛔ রুটহীন অনুরোধে পথের শেষ অংশ বানানো হল');
    }

    /** পাতার "কোথায় আছি" অংশটা — `data-page-path` থেকে `</nav>` পর্যন্ত */
    private function trailOf(string $url): string
    {
        $html = (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
        $start = strpos($html, 'data-page-path');
        $this->assertNotFalse($start, "প্রস্তুতিটাই ভুল — {$url}-এ \"কোথায় আছি\" নেই");

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
    }

    private function route(string $uri, string $name): Route
    {
        return (new Route(['GET'], $uri, fn () => null))->name($name);
    }
}
