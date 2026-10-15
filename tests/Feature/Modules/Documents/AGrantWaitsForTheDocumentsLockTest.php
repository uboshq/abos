<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Services\DocumentGrants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ অধিকার আর শেয়ার কাগজের সারিতে তালা দিয়ে লেখা — Documents লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[DocumentGrants]])।
 *
 * ⓘ দুই ক্লিক একসাথে এলে দুজনেই "এই মানুষের অধিকার নেই" দেখে নতুন সারি বসাতে যেত, আর অনন্য চাবিতে পাতা ৫০০। একসাথের দৌড় পরীক্ষায়
 * বানানো যায় না, তাই দাবিটা পথের: লেখার আগে কাগজের সারিতে FOR UPDATE পড়ে কি না ([[EveryMoneyActionLocksItsRowTest]]-এর একই ধাঁচ)।
 */
final class AGrantWaitsForTheDocumentsLockTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_grant_and_a_share_lock_the_document_before_writing(): void
    {
        $document = $this->upload('Price list');
        $person = $this->person('reader@abos.test', ['documents.view']);
        $this->actingAs($this->owner);
        $this->useCompany();

        foreach ([
            'অধিকার' => fn () => app(DocumentGrants::class)->grant($document, DocumentGrant::USER, (int) $person->id, ['download']),
            'শেয়ার' => fn () => app(DocumentGrants::class)->share($document, DocumentGrant::USER, (int) $person->id, false, null),
        ] as $what => $act) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $act();
            $locked = collect(DB::getQueryLog())->contains(fn (array $q) => str_contains(strtolower($q['query']), 'dms_documents')
                && str_contains(strtolower($q['query']), 'for update'));
            DB::disableQueryLog();

            $this->assertTrue($locked, "⛔ {$what}: কাগজের সারিতে তালা ছাড়াই লেখা — দুই ক্লিকে ৫০০");
        }

        $this->assertSame(1, DocumentGrant::query()->where('document_id', $document->id)->where('grantee_id', $person->id)->count());
    }
}
