<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ আপলোড করা ফাইল ব্রাউজারের দাবি করা ধরনে জমা হত আর সেই ধরনে পাতায় খুলত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১৩।
 *
 * এখন ধরন বাইট থেকে ([[AttachmentEngine]]), আর স্লিপ পাতায় খোলে কেবল ছবি বা PDF হলে; বাকি সব (পুরনো সারির ভুল ধরনসহ) নামানো
 * হিসেবে, `application/octet-stream` আর `nosniff`।
 */
final class ASlipCouldOpenAsAPageTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_the_type_is_read_from_the_bytes_and_only_a_photo_or_pdf_opens_in_the_page(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $shop = Customer::query()->orderBy('id')->firstOrFail();

        // ⓘ ফোনের জমার বিজ্ঞপ্তি — আসল PDF, কিন্তু ফোন ধরন বলল text/html
        Sanctum::actingAs($owner, [AuthController::APP]);
        $this->post('/api/v1/sales/deposit-requests', [
            'customer' => (string) $shop->public_id, 'claimed_on' => now()->toDateString(), 'amount' => '500',
            'method' => 'bank', 'slip' => $this->pdf('slip.pdf', 'text/html'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $claim = DepositClaim::query()->latest('id')->firstOrFail();
        $slip = app(\App\Modules\Sales\Services\DepositSlip::class)->of($claim);
        $this->assertNotNull($slip, 'প্রস্তুতিটাই ভুল — স্লিপ জমা হয়নি।');
        $this->assertSame('application/pdf', $slip->mime_type, '⛔ ব্রাউজারের দাবি করা ধরন জমা হলো।');

        $this->app['auth']->forgetGuards();
        $this->actingAs($owner);
        $open = $this->get(route('sales.claim.slip', $claim))->assertOk();
        $this->assertSame('application/pdf', $open->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $open->headers->get('Content-Disposition'));

        // ⓘ পুরনো সারি, ভুল ধরনে জমা — পাতায় নয়, নামানো
        $slip->forceFill(['mime_type' => 'text/html'])->save();
        $odd = $this->get(route('sales.claim.slip', $claim))->assertOk();
        $this->assertSame('application/octet-stream', $odd->headers->get('Content-Type'), '⛔ HTML ধরনের ফাইল পাতায় খুলল।');
        $this->assertStringStartsWith('attachment', (string) $odd->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $odd->headers->get('X-Content-Type-Options'));

        $this->assertNull(AttachmentEngine::inlineType('image/svg+xml'), '⛔ SVG-তে স্ক্রিপ্ট চলে — পাতায় নয়।');
        $this->assertSame('image/png', AttachmentEngine::inlineType('IMAGE/PNG '));
    }

    private function pdf(string $name, string $claimed): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'slip');
        $this->temp[] = $path;
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($path, $name, $claimed, null, true);
    }
}
