<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Documents\MakesDocuments;
use Tests\TestCase;

/**
 * ⭐ ফোন থেকে কাগজ তোলা আর ক্যামেরায় স্ক্যান — মালিক, ১৭ অক্টোবর ২০২৬ ([[DocumentLibraryApiController::upload()]])।
 *
 * দাবি: ওয়েবের তোলার একই নিয়ম আর সেবা — ফোনের ছবি একটা কাগজ হয়, শাখা public_id-তে, ভার্সনসহ; নাগালের বাইরের শাখা ৪২২; নিজের
 * ধাপের উপরের গোপনীয়তা ৪২২; অচেনা ফাইলের ধরন ৪২২; একই অনুরোধ দুবারে কাগজ একটাই; তোলার চাবি ছাড়া ৪০৩।
 */
final class ThePhoneUploadsDocumentsTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        app(SettingsService::class)->set(PhoneModules::key('documents'), true);
    }

    public function test_a_photo_from_the_phone_becomes_a_document_through_the_webs_own_rules(): void
    {
        $setup = $this->phone($this->owner)->getJson('/api/v1/documents/library/upload/setup')->assertOk();
        $this->assertContains((string) $this->main->public_id, array_column($setup->json('branches'), 'id'), '⛔ শাখা public_id-তে নয়।');
        $this->assertNotEmpty($setup->json('folders'));
        $this->useCompany();

        $key = (string) Str::uuid();
        $body = ['name' => 'Shop photo', 'doc_type' => 'contract', 'folder' => 'contracts', 'branch' => (string) $this->main->public_id,
            'confidentiality' => DocumentCatalog::INTERNAL];
        $made = $this->phone($this->owner)->withHeader('Idempotency-Key', $key)
            ->post('/api/v1/documents/library', $body + ['files' => [UploadedFile::fake()->image('shop.jpg', 640, 480)]], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->useCompany();
        $this->phone($this->owner)->withHeader('Idempotency-Key', $key)
            ->post('/api/v1/documents/library', $body + ['files' => [UploadedFile::fake()->image('shop.jpg', 640, 480)]], ['Accept' => 'application/json'])
            ->assertSuccessful();
        $this->useCompany();

        $paper = Document::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Shop photo')->get();
        $this->assertCount(1, $paper, '⛔ একই অনুরোধ দুবারে দুটো কাগজ।');
        $this->assertSame($this->main->id, (int) $paper[0]->branch_id);
        $this->assertNotNull($paper[0]->currentVersion, '⛔ কাগজে ভার্সন নেই।');
        $this->assertSame((string) $paper[0]->public_id, $made->json('rows.0.id'));
    }

    public function test_an_outside_branch_a_level_above_ones_own_and_a_strange_file_are_refused(): void
    {
        $clerk = $this->person('upload-clerk@abos.test', ['documents.view', 'documents.upload'], $this->main);
        $this->useCompany();
        $base = ['name' => 'X', 'doc_type' => 'contract', 'folder' => 'contracts', 'confidentiality' => DocumentCatalog::INTERNAL,
            'branch' => (string) $this->main->public_id];

        $this->send($clerk, ['branch' => (string) $this->netrakona->public_id] + $base, $this->pdf())->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->send($clerk, ['confidentiality' => DocumentCatalog::RESTRICTED] + $base, $this->pdf())->assertUnprocessable()->assertJsonValidationErrors('confidentiality');
        $this->send($clerk, $base, UploadedFile::fake()->createWithContent('run.exe', 'MZ'))->assertUnprocessable();

        $this->assertSame(0, Document::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'X')->count());
        $this->send($clerk, $base, $this->pdf())->assertCreated();
    }

    public function test_without_the_upload_key_the_door_is_shut(): void
    {
        $reader = $this->person('upload-reader@abos.test', ['documents.view']);
        $this->useCompany();

        $this->phone($reader)->getJson('/api/v1/documents/library/upload/setup')->assertForbidden();
        $this->send($reader, ['name' => 'Y', 'doc_type' => 'contract', 'folder' => 'contracts', 'confidentiality' => DocumentCatalog::INTERNAL,
            'branch' => (string) $this->main->public_id], $this->pdf())->assertForbidden();
    }

    /** @param  array<string, mixed>  $body */
    private function send(User $user, array $body, UploadedFile $file): TestResponse
    {
        $response = $this->phone($user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post('/api/v1/documents/library', $body + ['files' => [$file]], ['Accept' => 'application/json']);
        $this->useCompany();

        return $response;
    }

    private function phone(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
