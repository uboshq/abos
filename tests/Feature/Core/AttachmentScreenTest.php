<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ডকুমেন্টের কাগজপত্র — পর্দা থেকে।
 *
 * ── কেন এটা দরকার ছিল ───────────────────────────────────────────────
 * সংযুক্তির ইঞ্জিনটা অনেক আগেই লেখা হয়েছিল — নিরাপদ ফাইলের নাম,
 * নিষিদ্ধ এক্সটেনশন, ভার্সন, চেকসাম — আর তারপর কেউ কোনোদিন ডাকেনি।
 * ফলে সরবরাহকারীর আসল বিলটা কোথাও রাখার উপায় ছিল না, আর ছয় মাস পর
 * মিলিয়ে দেখার সময় কাগজটা খুঁজতে হত ফাইলের বাক্সে।
 */
class AttachmentScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private PurchaseBill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->bill = app(PurchaseBillService::class)->create(
            [
                'supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '5', 'rate' => '100']],
        );
    }

    /**
     * কাগজ তোলা যায়, আর সেটা ডকুমেন্টের পর্দাতেই দেখা যায়।
     */
    public function test_a_paper_can_be_added_to_a_document_and_shows_on_its_screen(): void
    {
        $this->post(route('attachment.store'), [
            'source_type' => PurchaseBill::drillSourceType(),
            'source_id' => $this->bill->id,
            'file' => UploadedFile::fake()->create('supplier-bill.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $paper = Attachment::query()->firstOrFail();

        $this->assertSame('supplier-bill.pdf', $paper->original_name);
        $this->assertSame('purchase', $paper->source_module);

        $this->get(route('purchase.bill.show', $this->bill))
            ->assertOk()
            ->assertSee('supplier-bill.pdf');
    }

    /**
     * খাতায় বসে যাওয়া বিলেও কাগজ তোলা যায়।
     *
     * ── কেন এটাই আসল ক্ষেত্র ────────────────────────────────────────
     * সরবরাহকারীর আসল বিলটা হাতে আসে পোস্ট করার পরে, কখনো পরদিন।
     * অনুমতিটা ডকুমেন্টের update নিয়মে বাঁধা থাকলে ("খসড়া হলে তবেই")
     * ঠিক এই ক্ষেত্রেই আপলোডের ঘরটা থাকত না — আর তখন ব্যবস্থাটা
     * বানানোর কারণটাই বাদ পড়ত।
     *
     * ধরা পড়েছে পর্দা চালিয়ে: নিশ্চিত বিলের পাতায় তালিকা ছিল, যোগ
     * করার ঘর ছিল না।
     */
    public function test_a_posted_bill_still_takes_papers(): void
    {
        $posted = app(PurchaseBillService::class)->confirm($this->bill);

        $this->post(route('attachment.store'), [
            'source_type' => PurchaseBill::drillSourceType(),
            'source_id' => $posted->id,
            'file' => UploadedFile::fake()->create('paper-bill.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame(1, Attachment::query()->count());

        // আর পর্দায় যোগ করার ঘরটাও থাকে
        $this->get(route('purchase.bill.show', $posted))
            ->assertOk()
            ->assertSee(__('core.attachment.upload'));
    }

    /**
     * কাগজটা নামানো যায়।
     */
    public function test_the_paper_comes_back_down(): void
    {
        $this->post(route('attachment.store'), [
            'source_type' => PurchaseBill::drillSourceType(),
            'source_id' => $this->bill->id,
            'file' => UploadedFile::fake()->create('slip.pdf', 10, 'application/pdf'),
        ]);

        $this->get(route('attachment.download', Attachment::query()->firstOrFail()))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=slip.pdf');
    }

    /**
     * ফাইলটা public ফোল্ডারে যায় না।
     *
     * ── কেন এটা পরীক্ষা করা হয় ──────────────────────────────────────
     * সরবরাহকারীর বিলে দাম লেখা, ব্যাংক স্লিপে হিসাব নম্বর। ফাইলটা
     * public/ থাকলে ঠিকানা জানা যে কেউ লগইন ছাড়াই খুলত — আর সেটা
     * কোনোদিন ধরা পড়ত না, কারণ পর্দায় সবই ঠিক দেখাত।
     */
    public function test_the_file_never_lands_in_a_public_folder(): void
    {
        $this->post(route('attachment.store'), [
            'source_type' => PurchaseBill::drillSourceType(),
            'source_id' => $this->bill->id,
            'file' => UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'),
        ]);

        $path = Attachment::query()->firstOrFail()->stored_path;

        $this->assertStringStartsWith('attachments/', $path);
        $this->assertStringNotContainsString('public', $path);
        Storage::disk('local')->assertExists($path);
    }

    /**
     * যাঁর ডকুমেন্টটা দেখার অনুমতি নেই, তিনি কাগজও পান না।
     *
     * অনুমতির প্রশ্নটা ডকুমেন্টের নিজের পলিসিকে করা হয় — কাগজের আলাদা
     * কোনো চাবি নেই। দুইটা আলাদা তালিকা থাকলে একদিন একটা বদলাত আর
     * অন্যটা থেকে যেত, আর ফাঁকটা থাকত কাগজের দিকেই।
     */
    public function test_someone_who_cannot_open_the_document_cannot_open_its_papers(): void
    {
        $this->post(route('attachment.store'), [
            'source_type' => PurchaseBill::drillSourceType(),
            'source_id' => $this->bill->id,
            'file' => UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'),
        ]);

        $outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $outsider->companies()->attach($this->company->id);

        $this->actingAs($outsider)
            ->get(route('attachment.download', Attachment::query()->firstOrFail()))
            ->assertForbidden();
    }

    /**
     * প্রোগ্রাম তোলা যায় না — নাম বদলালেও নয়।
     */
    public function test_a_program_cannot_be_uploaded(): void
    {
        $this->post(route('attachment.store'), [
            'source_type' => PurchaseBill::drillSourceType(),
            'source_id' => $this->bill->id,
            'file' => UploadedFile::fake()->create('payload.php', 10, 'application/pdf'),
        ])->assertSessionHasErrors();

        $this->assertSame(0, Attachment::query()->count());
    }

    /**
     * অচেনা ডকুমেন্টে কাগজ রাখা যায় না।
     *
     * ঠিকানায় হাতে টাইপ করা যেকোনো টাইপ নিলে কেউ এমন কিছুর নামে ফাইল
     * তুলত যার কোনো পলিসি নেই — অর্থাৎ অনুমতির পাহারাটাই এড়ানো যেত।
     */
    public function test_papers_cannot_be_hung_on_an_unknown_document(): void
    {
        $this->post(route('attachment.store'), [
            'source_type' => 'not_a_real_document',
            'source_id' => 1,
            'file' => UploadedFile::fake()->create('anything.pdf', 10, 'application/pdf'),
        ])->assertNotFound();
    }

    /**
     * সরানো কাগজ তালিকা থেকে যায়, কিন্তু ইতিহাস থেকে নয়।
     */
    public function test_removing_a_paper_leaves_a_trace(): void
    {
        $this->post(route('attachment.store'), [
            'source_type' => PurchaseBill::drillSourceType(),
            'source_id' => $this->bill->id,
            'file' => UploadedFile::fake()->create('wrong-photo.jpg', 10, 'image/jpeg'),
        ]);

        $paper = Attachment::query()->firstOrFail();

        $this->delete(route('attachment.destroy', $paper))->assertRedirect();

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame(1, Attachment::withTrashed()->count());
    }
}
