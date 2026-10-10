<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\Listing;
use App\Modules\Documents\Dashboard\DocumentsDashboard;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ড্যাশবোর্ডের "সদ্য জোড়া", ধরন আর মডিউলের টালি ডকুমেন্টের নিজের ফাইল দেখায় না — দেয়ালহীন টালিতে গোপন কাগজের নাম নয়
 * (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️৭)।
 */
final class TheDashboardDoesNotNameWalledFilesTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_restricted_documents_file_name_never_reaches_the_attachment_tiles(): void
    {
        $this->upload('Board pay', DocumentCatalog::RESTRICTED, $this->pdf('ceo-salary-2026.pdf'));

        $this->actingAs($this->person('dash-viewer@abos.test', ['documents.view']));
        $this->useCompany();

        $board = DocumentsDashboard::dashboard();

        $recent = collect($board->listings)->first(fn ($p) => $p instanceof Listing && $p->label === __('documents::dashboard.recent'));
        $this->assertNotNull($recent, 'ⓘ "সদ্য জোড়া" টালিই নেই — দাবিটা কিছু মাপছে না।');
        $this->assertSame([], collect($recent->rows)->pluck('original_name')->filter(fn ($n) => $n === 'ceo-salary-2026.pdf')->values()->all(),
            '⛔ সংরক্ষিত কাগজের ফাইলের নাম দেয়ালহীন টালিতে।');

        $modules = collect($board->panels)->first(fn ($p) => $p instanceof Breakdown && $p->label === __('documents::dashboard.by_module'));
        $docs = (new \ReflectionMethod(DocumentsDashboard::class, 'moduleName'))->invoke(null, 'documents');
        $this->assertNotContains($docs, collect($modules->parts)->pluck('label')->all(), '⛔ ডকুমেন্টের ফাইল "কোন কাজে জোড়া" টালিতে।');
    }
}
