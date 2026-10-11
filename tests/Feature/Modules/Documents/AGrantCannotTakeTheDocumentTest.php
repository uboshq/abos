<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Services\DataScope;
use App\Models\UserDataScope;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Services\DocumentAccess;
use App\Modules\Documents\Support\DocumentCatalog;
use App\Modules\MasterData\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ কাগজ-ধরে "বদল" অধিকার কাগজটা দখল করতে পারে না, আর মালিক বসানো একটা শেয়ার — চাবি ছাড়া নয় (১১ অক্টোবর ২০২৬, documents
 * রিভিউ ⛔৩, ⚠️৫, ⚠️৯; [[DocumentLibrary::guarded()]])।
 *
 * ⓘ মালিক সবসময় নিজের কাগজ দেখেন। আগে কাগজ-ধরে বদলের অধিকার পাওয়া মানুষ নিজেকে মালিক বানাতেন (অধিকার তুলে নিলেও দেখতেন),
 * বা "সংরক্ষিত" কাগজ "সবার" করে দিতেন; তোলার সময় যে কাউকে মালিক বসানো যেত; আর বিভাগে সীমিত মানুষ অন্য বিভাগে কাগজ রাখতেন।
 */
final class AGrantCannotTakeTheDocumentTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_an_edit_grant_changes_the_details_but_not_the_owner_or_the_level(): void
    {
        $document = $this->upload('Lease deed', DocumentCatalog::RESTRICTED);
        $editor = $this->person('grant-editor@abos.test', ['documents.view']);

        $this->actingAs($this->owner)->post(route('documents.grant.store', $document), [
            'grantee_type' => DocumentGrant::USER, 'grantee_id' => $editor->id, 'abilities' => ['edit'],
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $this->actingAs($editor)->put(route('documents.update', $document), [
            'name' => 'Lease deed (typo fixed)',
            'doc_type' => 'contract',
            'folder' => 'contracts',
            'branch_id' => $this->main->id,
            'confidentiality' => DocumentCatalog::PUBLIC,
            'owner_id' => $editor->id,
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $fresh = Document::query()->withoutGlobalScopes()->findOrFail($document->id);
        $this->assertSame('Lease deed (typo fixed)', $fresh->name, 'ⓘ বিবরণই বদলায়নি — অধিকারটা কাজ করছে না, দাবিটা কিছু মাপছে না।');
        $this->assertSame((int) $this->owner->id, (int) $fresh->owner_id, '⛔ কাগজ-ধরে অধিকারে মানুষ নিজেকে মালিক বানালেন।');
        $this->assertSame(DocumentCatalog::RESTRICTED, $fresh->confidentiality, '⛔ কাগজ-ধরে অধিকারে সংরক্ষিত কাগজ সবার হয়ে গেল।');
    }

    public function test_an_uploader_without_the_grant_key_cannot_name_someone_else_the_owner(): void
    {
        $uploader = $this->person('uploader@abos.test', ['documents.view', 'documents.upload']);
        $someone = $this->person('someone@abos.test', ['documents.view']);

        $this->actingAs($uploader)->post(route('documents.store'), [
            'name' => 'Visit report', 'doc_type' => 'contract', 'folder' => 'contracts', 'branch_id' => $this->main->id,
            'confidentiality' => DocumentCatalog::INTERNAL, 'files' => [$this->pdf()], 'owner_id' => $someone->id,
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $made = Document::query()->withoutGlobalScopes()->where('name', 'Visit report')->firstOrFail();
        $this->assertSame((int) $uploader->id, (int) $made->owner_id, '⛔ চাবি ছাড়াই অন্য কাউকে মালিক বসানো গেল — শেয়ারের চাবি ছাড়া শেয়ার।');

        // ⓘ অধিকার দেওয়ার চাবি যাঁর, তিনি পারেন
        $this->actingAs($this->owner)->post(route('documents.store'), [
            'name' => 'Handed over', 'doc_type' => 'contract', 'folder' => 'contracts', 'branch_id' => $this->main->id,
            'confidentiality' => DocumentCatalog::INTERNAL, 'files' => [$this->pdf()], 'owner_id' => $someone->id,
        ])->assertSessionHasNoErrors();
        $this->useCompany();
        $this->assertSame((int) $someone->id, (int) Document::query()->withoutGlobalScopes()->where('name', 'Handed over')->value('owner_id'));
    }

    public function test_a_department_limited_person_files_only_into_their_own_department(): void
    {
        $sales = Department::query()->create(['company_id' => $this->company->id, 'code' => 'SAL', 'name_en' => 'Sales', 'name_bn' => 'Sales', 'is_active' => true]);
        $hr = Department::query()->create(['company_id' => $this->company->id, 'code' => 'HRD', 'name_en' => 'HR', 'name_bn' => 'HR', 'is_active' => true]);

        $clerk = $this->person('dept-clerk@abos.test', ['documents.view', 'documents.upload']);
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => DocumentAccess::DEPARTMENT_SCOPE, 'scope_id' => $sales->id]);
        app(DataScope::class)->forget();

        $paper = fn (int $department) => ['name' => 'Filed paper '.$department, 'doc_type' => 'contract', 'folder' => 'contracts',
            'branch_id' => $this->main->id, 'confidentiality' => DocumentCatalog::INTERNAL, 'files' => [$this->pdf()], 'department_id' => $department];

        $this->actingAs($clerk)->post(route('documents.store'), $paper($hr->id))->assertSessionHasErrors('department_id');
        $this->useCompany();
        $this->actingAs($clerk)->post(route('documents.store'), $paper($sales->id))->assertSessionHasNoErrors();
    }
}
