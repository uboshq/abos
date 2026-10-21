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
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ⭐ সাধারণ কাগজের দরজা ফাইলের **বাইট** দেখে নেয়, নাম দেখে নয় — আর "না" বলে বাংলায় (ফাঁক ৪৮, fe-র বিক্রয়-ফাঁকের তালিকা, ২০ অক্টোবর ২০২৬)।
 *
 * ⓘ আসল রুটে (`attachment.store`), আসল ফাইলে — `UploadedFile::fake()` নয়, কারণ নকল ফাইল নিজের বলা ধরনটাই ফেরত দেয়, আর তখন
 * "বাইট দেখে" দাবিটা কিছুই মাপত না। ⛔ বিপজ্জনক উপাত্ত: PHP আর HTML, `.pdf` নামে; আর সাধারণ লেখা `.pdf` নামে — শেষটা কেবল
 * অনুমতি-তালিকাই ([[AttachmentEngine::GENERAL]]) ধরে, নিষিদ্ধ-তালিকা বা কোডের-ধরন যাচাই নয়।
 */
final class AnAttachmentIsTakenByWhatItIsNotByItsNameTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseBill $bill;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($owner->fresh());

        $this->bill = app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '5', 'rate' => '100']],
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_code_or_text_named_pdf_is_refused_in_bengali_through_the_route(): void
    {
        $files = [
            'invoice.pdf' => [$this->file('invoice.pdf', "<?php system(\$_GET['c']); ?>\n"), 'php'],
            'report.pdf' => [$this->file('report.pdf', "<!DOCTYPE html>\n<html><body><script>alert(document.cookie)</script></body></html>\n"), 'html'],
            'notes.pdf' => [$this->file('notes.pdf', "Only a line of plain text, not a PDF.\n"), 'text/plain'],
        ];

        foreach ($files as $name => [$file, $kind]) {
            // ⓘ উপাদান সত্যিই তাই — বাইট থেকে পড়া ধরন PDF নয়
            $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
            $this->assertStringContainsString($kind, $sniffed, "{$name}: বাইটের ধরন {$sniffed}");

            $response = $this->post(route('attachment.store'), [
                'source_type' => PurchaseBill::drillSourceType(),
                'source_id' => $this->bill->id,
                'file' => $file,
            ]);

            $response->assertRedirect();
            $this->assertSame([trans('attachment.wrong_kind', [], 'bn')], $this->fileErrors($response),
                "⛔ {$name} ({$sniffed}) — ফেরেনি, বা বার্তা বাংলায় নয়।");
        }

        $this->assertSame(0, Attachment::query()->count(), '⛔ নাম বদলানো ফাইলের সারি বসল।');
    }

    public function test_a_real_pdf_and_a_real_png_are_taken_through_the_route(): void
    {
        foreach (['bill.pdf' => $this->file('bill.pdf', $this->pdfBytes()), 'photo.png' => $this->png('photo.png')] as $name => $file) {
            $this->post(route('attachment.store'), [
                'source_type' => PurchaseBill::drillSourceType(),
                'source_id' => $this->bill->id,
                'file' => $file,
            ])->assertSessionHasNoErrors()->assertRedirect();

            $this->assertSame(1, Attachment::query()->where('original_name', $name)->count(), "⛔ আসল {$name} ফিরল।");
        }
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        $path = $this->tempPath();
        file_put_contents($path, $bytes);

        // ⓘ ব্রাউজারের দাবি "application/pdf" — দরজা তা মানে না
        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function png(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));

        $path = $this->tempPath();
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
            ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'g48');
        $this->temp[] = $path;

        return $path;
    }

    /** @return list<string> */
    private function fileErrors(TestResponse $response): array
    {
        $errors = session('errors');

        // ⓘ session JSON-এ থাকে, তাই থলেটা কখনো ViewErrorBag, কখনো তার সাজানো রূপ ([[ABankSlipIsAPhotoOrAPdfAndTheSignerCanOpenItTest]])
        if ($errors instanceof ViewErrorBag) {
            return $errors->getBag('default')->get('file');
        }

        return is_array($errors) ? array_values($errors['default']['messages']['file'] ?? []) : [];
    }
}
