<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ পর্যালোচনার মাঝে মেয়াদ পেরোনো কাগজ সিদ্ধান্তের পরে "মেয়াদোত্তীর্ণ" হয় — Documents লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[DocumentExpiry::run()]])।
 *
 * ⓘ পর্যালোচনার মাঝে অবস্থা বদলানো হয় না (ঠিকই), কিন্তু "মেয়াদ শেষ"-এর খবরের সারি তখনই বসত, আর সেই সারি থাকলে কাগজটা আর কখনো
 * তোলা হত না — অনুমোদনের পরে চিরকাল "অনুমোদিত", পেছনের মেয়াদ নিয়ে; নবায়নের পথ কোনোদিন খুলত না।
 */
final class ADocumentThatExpiredInReviewExpiresAfterwardsTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_paper_that_expired_under_review_is_expired_once_it_is_decided(): void
    {
        Carbon::setTestNow('2026-10-09 09:00:00');
        $document = $this->upload('Fire licence', extra: ['expiry_date' => '2026-10-20']);
        $this->useCompany();
        Document::query()->withoutGlobalScopes()->whereKey($document->id)->update(['status' => DocumentCatalog::SUBMITTED]);

        // ⓘ মেয়াদ পেরোল পর্যালোচনার মাঝে — অবস্থা বদলায় না
        Carbon::setTestNow('2026-10-25 09:00:00');
        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::SUBMITTED, Document::query()->withoutGlobalScopes()->findOrFail($document->id)->status);

        // ⓘ অনুমোদন হলো
        Document::query()->withoutGlobalScopes()->whereKey($document->id)->update(['status' => DocumentCatalog::APPROVED]);

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::EXPIRED, Document::query()->withoutGlobalScopes()->findOrFail($document->id)->status,
            '⛔ পর্যালোচনায় মেয়াদ পেরোনো কাগজ অনুমোদনের পরেও "অনুমোদিত" — চিরকাল');
    }
}
