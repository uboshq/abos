<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Notification;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Models\DocumentLink;
use App\Modules\Documents\Services\DocumentNotices;
use App\Modules\Documents\Support\DocumentCatalog;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * শেয়ার আর সম্পর্ক — ABOS-এর ভিতরে, মেয়াদসহ, আর কখনো দেয়াল পেরিয়ে নয় (পরিকল্পনা §১৪, §১৫; চতুর্থ ধাপ)।
 */
final class AShareAndALinkKeepToTheWallsTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_view_only_share_opens_until_its_date_and_then_shuts_itself(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');
        $document = $this->upload('Tender drawings', DocumentCatalog::CONFIDENTIAL);
        $guest = $this->person('share-guest@abos.test', ['documents.view']);

        $this->actingAs($this->owner)->post(route('documents.share.store', $document), [
            'grantee_type' => DocumentGrant::USER, 'grantee_id' => $guest->id, 'expires_on' => '2026-10-12',
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $shared = (string) $this->actingAs($guest)->get(route('documents.shared'))->assertOk()->getContent();
        $this->assertStringContainsString(route('documents.show', $document), $shared, 'শেয়ার করা কাগজ "শেয়ার করা" পর্দায় নেই।');
        $this->actingAs($guest)->get(route('documents.show', $document))->assertOk();
        $this->actingAs($guest)->get(route('documents.preview', $document))->assertOk();
        // ⓘ কেবল দেখা — নামানো নয়
        $this->assertContains($this->actingAs($guest)->get(route('documents.download', $document))->getStatusCode(), [403, 404]);
        $this->useCompany();

        $this->assertTrue(Notification::query()->withoutGlobalScopes()->where('user_id', $guest->id)
            ->where('type', DocumentNotices::SHARED)->exists(), 'শেয়ারের খবর যায়নি।');
        $this->assertTrue(AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_shared')->exists());

        Carbon::setTestNow('2026-10-13 09:00:00');
        $this->assertContains($this->actingAs($guest)->get(route('documents.show', $document))->getStatusCode(), [403, 404],
            'মেয়াদ পেরোনো শেয়ার এখনো কাগজ খোলে।');
        $this->assertStringNotContainsString(route('documents.show', $document),
            (string) $this->actingAs($guest)->get(route('documents.shared'))->assertOk()->getContent());
    }

    public function test_sharing_needs_its_key_or_a_share_right_and_never_crosses_a_branch(): void
    {
        $document = $this->upload('Branch memo');
        $sharer = $this->person('no-share@abos.test', ['documents.view']);
        $target = $this->person('share-target@abos.test', ['documents.view']);

        $this->actingAs($sharer)->post(route('documents.share.store', $document), [
            'grantee_type' => DocumentGrant::USER, 'grantee_id' => $target->id,
        ])->assertForbidden();
        $this->useCompany();

        // ⓘ একই মানুষ — এবার এই কাগজে "শেয়ার" অধিকার
        $this->actingAs($this->owner)->post(route('documents.grant.store', $document), [
            'grantee_type' => DocumentGrant::USER, 'grantee_id' => $sharer->id, 'abilities' => ['share'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($sharer)->post(route('documents.share.store', $document), [
            'grantee_type' => DocumentGrant::USER, 'grantee_id' => $target->id,
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $far = $this->person('ntk-share@abos.test', ['documents.view'], limitedTo: $this->netrakona);
        $this->actingAs($this->owner)->post(route('documents.share.store', $document), [
            'grantee_type' => DocumentGrant::USER, 'grantee_id' => $far->id, 'download' => '1',
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $this->assertContains($this->actingAs($far)->get(route('documents.show', $document))->getStatusCode(), [403, 404],
            'শেয়ার অন্য শাখার মানুষের জন্য কাগজ খুলল।');
        $this->assertSame(0, Notification::query()->withoutGlobalScopes()->where('user_id', $far->id)->count(),
            'অন্য শাখার মানুষ শেয়ারের খবরে কাগজের নাম পেলেন।');
    }

    public function test_a_document_links_to_a_supplier_and_shows_on_its_page_only_to_those_who_may_see_it(): void
    {
        $supplier = Supplier::query()->create(['code' => 'SUP-DOC1', 'name_en' => 'Padma Packaging', 'name_bn' => 'পদ্মা প্যাকেজিং', 'is_active' => true]);
        $secret = $this->upload('Padma supply agreement', DocumentCatalog::CONFIDENTIAL);

        $found = (string) $this->actingAs($this->owner)
            ->get(route('documents.show', ['document' => $secret, 'link_type' => 'supplier', 'link_q' => 'Padma']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-candidate="supplier-'.$supplier->id.'"', $found, 'খোঁজে সরবরাহকারী এল না।');

        $this->actingAs($this->owner)->post(route('documents.link.store', $secret), [
            'source_type' => 'supplier', 'source_id' => $supplier->id,
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $this->assertTrue(AuditTrail::query()->forRecord(Document::class, $secret->id)->where('action', 'document_linked')->exists());

        $page = (string) $this->actingAs($this->owner)->get(route('supplier.show', $supplier))->assertOk()->getContent();
        $this->assertStringContainsString('Padma supply agreement', $page, 'সরবরাহকারীর পাতায় জোড়া কাগজ নেই।');
        $this->useCompany();

        // ⛔ যিনি গোপন কাগজ দেখেন না, সরবরাহকারীর পাতায় তিনি কাগজটার নামও দেখেন না
        $buyer = $this->person('buyer@abos.test', ['documents.view', 'supplier.view']);
        $other = (string) $this->actingAs($buyer)->get(route('supplier.show', $supplier))->assertOk()->getContent();
        $this->assertStringNotContainsString('Padma supply agreement', $other, 'সরবরাহকারীর পাতা গোপন কাগজের নাম ফাঁস করল।');
    }

    public function test_a_link_cannot_reach_another_companys_record(): void
    {
        $document = $this->upload('Our agreement');
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        $theirs = CompanyContext::forCompany($other->id, fn () => Supplier::query()->create([
            'company_id' => $other->id, 'code' => 'SUP-FM1', 'name_en' => 'Their supplier', 'is_active' => true,
        ]));
        $this->useCompany();

        $this->actingAs($this->owner)->post(route('documents.link.store', $document), [
            'source_type' => 'supplier', 'source_id' => $theirs->id,
        ])->assertSessionHasErrors('source_id');
        $this->useCompany();

        $this->assertSame(0, DocumentLink::query()->withoutGlobalScopes()->count(), 'অন্য কোম্পানির রেকর্ডে জোড়া বসল।');
    }
}
