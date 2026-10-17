<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use App\Modules\Documents\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Documents\MakesDocuments;
use Tests\TestCase;

/**
 * ⭐ দলিলের ছাঁচ ফোনে পড়া — মালিক, ১৭ অক্টোবর ২০২৬ ([[DocumentTemplateApiController]])।
 *
 * দাবি: তালিকা ওয়েবের পাতার একই সারি (নাম ধরে), public_id-তে; একটা ছাঁচ খুললে তার লেখা, ট্যাগ ছাড়া, খালি ঘরের চিহ্ন অক্ষত —
 * ⛔ কেবল ছাঁচের চাবিতে (documents.templates), তালিকার চাবিতে ৪০৩;
 * সংখ্যার id-তে ৪০৪; চাবি ছাড়া ৪০৩; ⛔ ফোনে লেখার কোনো দরজা নেই।
 */
final class ThePhoneReadsDocumentTemplatesTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        app(SettingsService::class)->set(PhoneModules::key('documents'), true);
    }

    public function test_the_list_and_one_template_read_like_the_web(): void
    {
        $this->useCompany();
        $b = DocumentTemplate::query()->create(['company_id' => $this->company->id, 'code' => 'TPL-B', 'title' => 'Bank letter',
            'doc_type' => 'letter', 'folder' => 'company', 'body' => '<p>Dear {{bank_name}},</p><p>Regards</p>', 'is_active' => true]);
        DocumentTemplate::query()->create(['company_id' => $this->company->id, 'code' => 'TPL-A', 'title' => 'Appointment',
            'doc_type' => 'letter', 'folder' => 'company', 'body' => '<p>Hello</p>', 'is_active' => true]);
        $reader = $this->person('tpl-reader@abos.test', ['documents.view', 'documents.templates']);
        $lister = $this->person('tpl-lister@abos.test', ['documents.view']);
        $this->useCompany();

        $list = $this->phone($reader, '/api/v1/documents/templates')->assertOk();
        $this->assertSame(['Appointment', 'Bank letter'], array_column($list->json('rows'), 'title'), '⛔ ওয়েবের তালিকার ক্রম (নাম ধরে) নয়।');
        $this->assertSame(2, $list->json('count'));
        $this->assertSame((string) $b->public_id, $list->json('rows.1.id'), '⛔ ছাঁচ public_id-তে নয়।');

        $one = $this->phone($reader, '/api/v1/documents/templates/'.$b->public_id)->assertOk();
        $this->assertSame("Dear {{bank_name}},\nRegards", $one->json('body'), '⛔ লেখা ট্যাগসহ, বা খালি ঘরের চিহ্ন হারিয়েছে।');

        $this->phone($reader, '/api/v1/documents/templates/'.$b->id)->assertNotFound();

        // ⛔ তালিকা দেখার চাবিতে লেখা নয় — ওয়েবের সম্পাদকের মতো documents.templates লাগে
        $this->assertSame(2, $this->phone($lister, '/api/v1/documents/templates')->assertOk()->json('count'));
        $this->phone($lister, '/api/v1/documents/templates/'.$b->public_id)->assertForbidden();
    }

    public function test_without_the_key_it_is_refused_and_there_is_no_door_to_write(): void
    {
        $nobody = $this->person('tpl-nobody@abos.test', []);
        $this->useCompany();
        $this->phone($nobody, '/api/v1/documents/templates')->assertForbidden();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $this->postJson('/api/v1/documents/templates', ['title' => 'x'])->assertStatus(405);
    }

    private function phone(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        $response = $this->getJson($url);
        $this->useCompany();

        return $response;
    }
}
