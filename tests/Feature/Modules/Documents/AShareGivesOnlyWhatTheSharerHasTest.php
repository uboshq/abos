<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ শেয়ার বা অধিকার নিজেকে নয়, আর নিজের নেই এমন নামানো নয় (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️৪, ⚠️১০)।
 *
 * ⓘ আগে কেবল `documents.share` থাকা মানুষ নিজেকেই "নামানো সহ" শেয়ার করে কাগজ নামিয়ে নিতেন, আর `documents.permissions` থাকা মানুষ
 * নিজেকে বদল/শেয়ার/নামানোর অধিকার দিতেন — চাবির বাইরে নিজের হাত।
 */
final class AShareGivesOnlyWhatTheSharerHasTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_sharer_cannot_share_to_themselves_or_hand_out_a_download_they_lack(): void
    {
        $document = $this->upload('Price circular', DocumentCatalog::INTERNAL);
        $sharer = $this->person('sharer@abos.test', ['documents.view', 'documents.share']);
        $colleague = $this->person('colleague@abos.test', ['documents.view']);

        $share = fn (int $to, bool $download) => $this->actingAs($sharer)->post(route('documents.share.store', $document),
            ['grantee_type' => DocumentGrant::USER, 'grantee_id' => $to, 'download' => $download ? '1' : '0']);

        $share((int) $sharer->id, true)->assertSessionHasErrors('grantee_id');
        $this->useCompany();
        $share((int) $colleague->id, true)->assertSessionHasErrors('download');
        $this->useCompany();
        $share((int) $colleague->id, false)->assertSessionHasNoErrors();
        $this->useCompany();

        $this->assertSame(0, DocumentGrant::query()->where('document_id', $document->id)->where('grantee_id', $sharer->id)->count(), '⛔ নিজেকে শেয়ার বসে গেল।');
        $this->assertFalse((bool) DocumentGrant::query()->where('document_id', $document->id)->where('grantee_id', $colleague->id)->value('can_download'),
            '⛔ নিজের না থাকা নামানো অন্যকে দেওয়া গেল।');
    }

    public function test_the_grant_key_does_not_grant_its_holder(): void
    {
        $document = $this->upload('Board minutes', DocumentCatalog::INTERNAL);
        $keeper = $this->person('grant-keeper@abos.test', ['documents.view', 'documents.permissions']);

        $this->actingAs($keeper)->post(route('documents.grant.store', $document), [
            'grantee_type' => DocumentGrant::USER, 'grantee_id' => $keeper->id, 'abilities' => ['edit', 'download'],
        ])->assertSessionHasErrors('grantee_id');
        $this->useCompany();

        $this->assertSame(0, DocumentGrant::query()->where('document_id', $document->id)->where('grantee_id', $keeper->id)->count());
    }
}
