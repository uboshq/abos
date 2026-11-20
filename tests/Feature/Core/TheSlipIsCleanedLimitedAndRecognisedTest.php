<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\NotASlip;
use App\Core\Engines\Attachment\SeenBefore;
use App\Core\Engines\Image\ImageEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ জমার স্লিপ — পরিষ্কার, ধরন ধরে সীমা, আর একই স্লিপ দুইবার চেনা (মালিক, ১৬ নভেম্বর ২০২৬: "deposit report flexible —
 *   WhatsApp photos ok, bank statement is final"; ২০ নভেম্বর ২০২৬)।
 *
 *   - ফোনের ছবির লুকানো তথ্য (EXIF, জিপিএস) জমা ফাইলে থাকে না, ছবি ছোট হয়, লম্বা বাহু ২০০০-এর বেশি নয়
 *   - GD না পারলেও (পড়ে-যাওয়া পথ) বাইট থেকে EXIF কাটা যায়; অচেনা বাইট যেমন ছিল তেমন
 *   - ছবি আর PDF-এর সীমা কন্ট্রোল প্যানেলে, KB-তে; ছাড়ালে বাংলায় কারণ — কোন ধরন, কত বড়, সীমা কত
 *   - হুবহু একই PDF দুবার: সতর্কতা আর আগের কাগজের লিংক — কিন্তু দাবি জমা হয়, আটকায় না; ফোনের উত্তরেও একই
 */
final class TheSlipIsCleanedLimitedAndRecognisedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->dealer = Customer::query()->inViewedBranch()->orderBy('id')->firstOrFail();
        app()->setLocale('bn');
    }

    public function test_a_phone_photo_is_kept_without_its_gps_and_smaller(): void
    {
        $photo = $this->photoWithGps(3000, 4000);
        $before = (int) filesize($photo->getRealPath());

        $this->actingAs($this->person())->post(route('sales.claim.request.store'), $this->form('GPS-1', $photo))
            ->assertSessionHasNoErrors()->assertRedirect();

        $slip = $this->slipOfLatest();
        $bytes = Storage::disk('local')->get($slip->stored_path);

        $this->assertStringNotContainsString("Exif\0\0", $bytes, '⛔ জমা স্লিপে EXIF রয়ে গেছে');
        $this->assertStringNotContainsString('GPSLatitude', $bytes, '⛔ জমা স্লিপে দোকানের জিপিএস রয়ে গেছে');
        $this->assertSame('image/jpeg', $slip->mime_type);
        $this->assertLessThan($before, strlen($bytes), '⛔ স্লিপ ছোট হয়নি');

        [$w, $h] = getimagesizefromstring($bytes);
        $this->assertLessThanOrEqual(ImageEngine::PAPER_EDGE, max($w, $h), '⛔ লম্বা বাহু ২০০০-এর বেশি');
        $this->assertSame(hash('sha256', $bytes), $slip->checksum, '⛔ বাইটের ছাপ পরিষ্কার করা ফাইলের নয়');
    }

    public function test_metadata_is_cut_from_the_bytes_when_the_picture_is_not_redrawn(): void
    {
        $jpeg = file_get_contents($this->photoWithGps(40, 30)->getRealPath());
        $clean = ImageEngine::withoutMetadata($jpeg, 'image/jpeg');
        $this->assertStringContainsString('GPSLatitude', $jpeg);
        $this->assertStringNotContainsString('GPSLatitude', $clean, '⛔ JPEG-এর APP1 কাটা যায়নি');
        $this->assertNotFalse(@imagecreatefromstring($clean), '⛔ কাটার পরে ছবিটা আর খোলে না');

        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        // ⓘ IHDR-এর পরে একটা tEXt টুকরো — "GPS 23.81N"
        $text = 'Comment'."\0".'GPS 23.81N';
        $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));
        $withText = substr($png, 0, 33).$chunk.substr($png, 33);
        $cleanPng = ImageEngine::withoutMetadata($withText, 'image/png');
        $this->assertStringNotContainsString('GPS 23.81N', $cleanPng, '⛔ PNG-এর লেখার টুকরো কাটা যায়নি');
        $this->assertNotFalse(@imagecreatefromstring($cleanPng));

        // ⓘ অচেনা বাইট — যেমন ছিল তেমন, কাগজ হারায় না
        $this->assertSame('not a picture', ImageEngine::withoutMetadata('not a picture', 'image/jpeg'));
    }

    public function test_each_kind_has_its_own_limit_from_the_control_panel_and_says_why_in_bengali(): void
    {
        app(SettingsService::class)->set('system.slip_max_kb.pdf', 50);
        $this->assertSame(50, AttachmentEngine::slipMaxKb('pdf'));
        $this->assertSame(AttachmentEngine::SLIP_IMAGE_MAX_KB, AttachmentEngine::slipMaxKb('image'));

        $response = $this->actingAs($this->person())->post(route('sales.claim.request.store'), $this->form('BIG-PDF', $this->pdf(80, 'big')));
        $response->assertSessionHasErrors('slip');
        $message = (string) session('errors')->first('slip');
        $this->assertStringContainsString('PDF', $message);
        $this->assertStringContainsString('50 KB', $message, '⛔ বার্তায় সীমা নেই');
        $this->assertSame(0, DepositClaim::query()->where('reference', 'BIG-PDF')->count(), '⛔ সীমা ছাড়ানো স্লিপসহ দাবি উঠে গেছে');

        // ⓘ সীমার ভিতরের PDF চলে
        $this->actingAs($this->person())->post(route('sales.claim.request.store'), $this->form('SMALL-PDF', $this->pdf(20, 'small')))
            ->assertSessionHasNoErrors();

        // ⛔ সেটিংসে বিশাল সংখ্যা বসালেও ছাদ ১০ MB
        app(SettingsService::class)->set('system.slip_max_kb.image', 999999);
        $this->assertSame(AttachmentEngine::SLIP_HARD_MAX_KB, AttachmentEngine::slipMaxKb('image'));

        $refusal = new NotASlip(NotASlip::TOO_BIG, kind: 'image', maxKb: 5120, sizeKb: 6400);
        $this->assertStringContainsString('5 MB', $refusal->text());
        $this->assertStringContainsString('6.3 MB', $refusal->text());
    }

    public function test_the_same_pdf_twice_warns_with_a_link_and_does_not_block(): void
    {
        $sr = $this->person();
        $this->actingAs($sr)->post(route('sales.claim.request.store'), $this->form('DUP-1', $this->pdf(20, 'same')))->assertSessionHasNoErrors();
        $first = DepositClaim::query()->latest('id')->firstOrFail();

        $again = $this->actingAs($sr)->post(route('sales.claim.request.store'), $this->form('DUP-2', $this->pdf(20, 'same')));
        $again->assertSessionHasNoErrors()->assertSessionHas('warning')->assertSessionHas('warning_link');
        $this->assertStringContainsString('claim='.$first->id, (string) session('warning_link'), '⛔ লিংক আগের জমার খবরে যায় না');

        $copy = DepositClaim::query()->latest('id')->firstOrFail();
        $this->assertSame('DUP-2', $copy->reference, '⛔ সতর্কতার বদলে আটকে দিয়েছে');
        $this->assertSame($first->id, (int) $copy->lookalike_claim_id, '⛔ হুবহু একই PDF চেনা যায়নি');

        // ⓘ আলাদা PDF — কোনো সতর্কতা নয়
        $this->actingAs($sr)->post(route('sales.claim.request.store'), $this->form('DUP-3', $this->pdf(20, 'other')))
            ->assertSessionHasNoErrors()->assertSessionMissing('warning');

        // ⓘ লিংকটা সত্যিই ঐ একটা খবর খোলে
        $desk = $this->person(['sales.claim.view']);
        $this->actingAs($desk)->get(route('sales.claim.index', ['status' => 'all', 'claim' => $first->id]))
            ->assertOk()->assertSee('DUP-1')->assertDontSee('DUP-3');
    }

    public function test_a_slip_seen_on_another_paper_names_it(): void
    {
        $older = $this->attachmentRow(DepositClaim::class, 999001, str_repeat('a', 64));
        $newer = $this->attachmentRow('voucher', 12, str_repeat('a', 64));
        $sameDoc = $this->attachmentRow('voucher', 12, str_repeat('b', 64));

        $seen = app(SeenBefore::class)->of($newer);
        $this->assertNotNull($seen, '⛔ অন্য কাগজের একই স্লিপ চেনা যায়নি');
        $this->assertSame($older->id, $seen['attachment']->id);
        $this->assertStringContainsString('999001', $seen['paper']);
        $this->assertStringContainsString('claim=999001', (string) $seen['url']);
        $this->assertStringContainsString('একই স্লিপ দুইবার', $seen['message']);

        // ⓘ একই কাগজে নতুন সংস্করণ নিজের সাথে মেলে না
        $this->assertNull(app(SeenBefore::class)->of($sameDoc));
    }

    public function test_the_phone_gets_the_same_cleaning_limit_and_warning(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);
        $body = fn (string $ref, UploadedFile $slip) => [
            'customer' => (string) $this->dealer->public_id, 'claimed_on' => now()->toDateString(), 'amount' => '1500',
            'method' => 'cash', 'reference' => $ref, 'slip' => $slip,
        ];

        $first = $this->post('/api/v1/sales/deposit-requests', $body('APP-1', $this->pdf(20, 'phone')), ['Accept' => 'application/json']);
        $first->assertCreated()->assertJsonPath('seen_before', null);

        $second = $this->post('/api/v1/sales/deposit-requests', $body('APP-2', $this->pdf(20, 'phone')), ['Accept' => 'application/json']);
        $second->assertCreated();
        $this->assertNotEmpty($second->json('seen_before.message'), '⛔ ফোন সতর্কতা পায়নি');
        $this->assertNotEmpty($second->json('seen_before.url'));

        app(SettingsService::class)->set('system.slip_max_kb.pdf', 50);
        $this->post('/api/v1/sales/deposit-requests', $body('APP-3', $this->pdf(80, 'phone big')), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('slip');

        $photo = $this->post('/api/v1/sales/deposit-requests', $body('APP-4', $this->photoWithGps(2400, 1800)), ['Accept' => 'application/json']);
        $photo->assertCreated();
        $bytes = Storage::disk('local')->get($this->slipOfLatest()->stored_path);
        $this->assertStringNotContainsString('GPSLatitude', $bytes, '⛔ ফোনের পথে জিপিএস রয়ে গেছে');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  list<string>  $keys */
    private function person(array $keys = ['sales.claim.submit']): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->givePermissionTo($keys);

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function form(string $reference, UploadedFile $slip): array
    {
        return [
            'customer' => (string) $this->dealer->id, 'claimed_on' => now()->subDay()->toDateString(), 'amount' => '45000',
            'method' => DepositClaim::CASH, 'reference' => $reference, 'slip' => $slip,
        ];
    }

    private function slipOfLatest(): Attachment
    {
        return Attachment::query()->where('source_entity', DepositClaim::class)->latest('id')->firstOrFail();
    }

    /** ফোনের ছবি — GD-র JPEG, শুরুতে জিপিএসসহ একটা EXIF টুকরো */
    private function photoWithGps(int $width, int $height): UploadedFile
    {
        mt_srand(11);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 235, 232, 225));
        for ($i = 0; $i < 400; $i++) {
            $c = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $c);
        }
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string) ob_get_clean();

        $payload = "Exif\0\0".'MM'."\0*\0\0\0\x08".'GPSLatitude 23.8103N GPSLongitude 90.4125E iPhone 15';
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $path = tempnam(sys_get_temp_dir(), 'gps').'.jpg';
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return new UploadedFile($path, 'IMG_20261120.jpg', 'image/jpeg', null, true);
    }

    /** একটা PDF — `$seed` একই হলে বাইটও একই */
    private function pdf(int $kb, string $seed): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% ".$seed."\n";
        $body .= '% '.str_repeat(substr(hash('sha256', $seed), 0, 60)."\n% ", intdiv($kb * 1024, 63))."\n%%EOF\n";
        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'slip.pdf', 'application/pdf', null, true);
    }

    private function attachmentRow(string $entity, int $id, string $checksum): Attachment
    {
        return Attachment::query()->create([
            'company_id' => $this->company->id, 'source_module' => 'sales', 'source_entity' => $entity, 'source_entity_id' => $id,
            'original_name' => 'slip.pdf', 'stored_path' => 'x/'.$checksum.$id.'.pdf', 'mime_type' => 'application/pdf',
            'extension' => 'pdf', 'size_bytes' => 100, 'checksum' => $checksum, 'version' => 1,
        ]);
    }
}
