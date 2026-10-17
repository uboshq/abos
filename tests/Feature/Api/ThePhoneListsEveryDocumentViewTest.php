<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Documents\MakesDocuments;
use Tests\TestCase;

/**
 * ⭐ ওয়েবের আটটা দলিল-তালিকা ফোনেও — কেন্দ্র, আমার, শেয়ার, মেয়াদ, সাম্প্রতিক, আর্কাইভ, রিসাইকেল বিন, বিস্তারিত খোঁজ (মালিক, ১৭ অক্টোবর
 * ২০২৬: *"direct webpage app e bujajayna"*; [[DocumentLibraryApiController::documents()]])।
 *
 * দাবি: আর্কাইভ করা কাগজ কেন্দ্রে নেই, আর্কাইভে আছে, খোঁজে আছে; মোছা কাগজ কেবল বিনে, "মোছা" চিহ্নসহ; সাম্প্রতিক = যা আমি দেখেছি;
 * প্রতিটা তালিকা গোটা ছাঁকনির গোনা পাঠায়; অচেনা ঘর কেন্দ্রে ফেরে।
 */
final class ThePhoneListsEveryDocumentViewTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        app(SettingsService::class)->set(PhoneModules::key('documents'), true);
    }

    public function test_archive_recycle_search_and_recent_show_what_the_web_pages_show(): void
    {
        $live = $this->upload('Trade licence');
        $archived = $this->upload('Old lease');
        $deleted = $this->upload('Wrong scan');
        $this->useCompany();
        Document::query()->whereKey($archived->id)->update(['archived_at' => now()]);
        Document::query()->whereKey($deleted->id)->first()->delete();

        $ids = fn (string $view, string $q = '') => array_column(
            $this->phone($this->owner, '/api/v1/documents/library?view='.$view.($q !== '' ? '&q='.urlencode($q) : ''))->assertOk()->json('rows'), 'id');

        $this->assertEqualsCanonicalizing([$live->public_id], $ids('center'), '⛔ কেন্দ্রে আর্কাইভ বা মোছা কাগজ।');
        $this->assertSame([$archived->public_id], $ids('archive'), '⛔ আর্কাইভের ঘরে অন্য কাগজ, বা আর্কাইভটা নেই।');
        $this->assertSame([$deleted->public_id], $ids('recycle'), '⛔ বিনে অন্য কাগজ, বা মোছাটা নেই।');
        $this->assertEqualsCanonicalizing([$live->public_id, $archived->public_id], $ids('search'), '⛔ খোঁজে আর্কাইভ নেই, বা মোছা ঢুকেছে।');
        $this->assertSame([$archived->public_id], $ids('search', 'lease'));

        $bin = $this->phone($this->owner, '/api/v1/documents/library?view=recycle')->assertOk();
        $this->assertTrue($bin->json('rows.0.deleted'));
        $this->assertSame(1, $bin->json('count'));
        $this->assertSame('recycle', $bin->json('view'));

        // ⓘ সাম্প্রতিক — যা আমি খুলেছি (খোলা অডিটে "দেখা")
        $this->phone($this->owner, '/api/v1/documents/library/'.$live->public_id)->assertOk();
        $this->assertContains($live->public_id, $ids('recent'));

        $this->assertSame('center', $this->phone($this->owner, '/api/v1/documents/library?view=ghost')->json('view'), 'অচেনা ঘর কেন্দ্রে ফেরে');
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
