<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Modules\Documents\Support\DocumentCatalog;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * ⛔ ডকুমেন্টের রিপোর্ট দেয়াল ছাড়া খোলে না, আর নির্ধারিত রিপোর্ট প্রত্যেক প্রাপক নিজের চোখে পান (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️৬)।
 *
 * ⓘ রিপোর্টের গোড়া পরিচয় না পেলে দেয়ালটাই বাদ দিত (খোলা রেখে ব্যর্থ); আর নির্ধারিত রিপোর্টের ভাগের ফাইল সূচির মালিকের চোখে বানানো, ফলে
 * "সংরক্ষিত" চাবি ছাড়া বা অন্য বিভাগের প্রাপকও গোপন কাগজের নাম পেতেন।
 */
final class AScheduledDocumentReportKeepsEachReadersWallsTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_without_a_person_the_register_shows_nothing_and_with_the_owner_it_shows_the_paper(): void
    {
        $this->upload('Secret lease', DocumentCatalog::RESTRICTED);

        // ⓘ রিপোর্টের গোড়া নিজে — দরজার চাবির প্রশ্ন বাদ দিয়ে, কেবল দেয়াল
        $base = new \ReflectionMethod(\App\Modules\Documents\Reports\DocumentReports::class, 'documents');
        $filters = ['company_id' => $this->company->id];

        $this->actingAs($this->owner);
        $this->useCompany();
        $this->assertGreaterThan(0, $base->invoke(null, $filters)->count(), 'ⓘ মালিকও কাগজটা দেখেন না — দাবিটা কিছু মাপছে না।');

        Auth::logout();
        $this->useCompany();
        $this->assertSame(0, $base->invoke(null, $filters)->count(), '⛔ পরিচয় ছাড়া রিপোর্ট দেয়াল ছাড়াই সব কাগজ দেখাল।');
    }

    public function test_a_scheduled_document_report_is_made_for_each_reader(): void
    {
        $per = new \ReflectionMethod(ScheduledReportRunner::class, 'readsPerPerson');

        foreach (['documents.register', 'documents.summary', 'documents.access_history'] as $key) {
            $this->assertTrue($per->invoke(null, $key), "⛔ {$key}: ভাগের ফাইলে প্রাপক মালিকের চোখে কাগজ দেখতেন।");
        }

        $this->assertFalse($per->invoke(null, 'sales.by_customer'), 'বাকি রিপোর্ট আগের মতো ভাগের ফাইলেই।');
    }
}
