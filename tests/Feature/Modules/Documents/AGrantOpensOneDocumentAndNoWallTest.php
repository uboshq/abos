<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Services\DataScope;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Services\DocumentAccess;
use App\Modules\Documents\Support\DocumentCatalog;
use App\Modules\MasterData\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * দ্বিতীয় ধাপের দেয়াল — বিভাগ, কাগজ-ধরে অধিকার, আর অবস্থার তালা (পরিকল্পনা §১৩, §২২)।
 *
 * ── ⓘ দাবির নিয়ম ─────────────────────────────────────────────────────
 * একই মানুষ, একই কাগজ — কেবল অধিকার বা সীমা বদলায়: আগে বন্ধ, পরে খোলা, সরালে আবার বন্ধ।
 * ⚠️ নাহলে "খোলা" দাবিটা একটা ঢিলা দরজাতেও সবুজ হত।
 */
final class AGrantOpensOneDocumentAndNoWallTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    private const READER = ['documents.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_grant_opens_exactly_one_confidential_document_and_removing_it_shuts_it_again(): void
    {
        $granted = $this->upload('Board minutes March', DocumentCatalog::CONFIDENTIAL);
        $other = $this->upload('Board minutes April', DocumentCatalog::CONFIDENTIAL);

        $reader = $this->person('grant-reader@abos.test', self::READER);

        $this->assertShut($reader, $granted, 'Board minutes March');

        $this->actingAs($this->owner)
            ->post(route('documents.grant.store', $granted), [
                'grantee_type' => DocumentGrant::USER,
                'grantee_id' => $reader->id,
                'abilities' => ['download'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->useCompany();

        $list = $this->listFor($reader);
        $this->assertStringContainsString('Board minutes March', $list, 'অধিকার দেওয়ার পরেও কাগজ তালিকায় নেই।');
        $this->assertStringNotContainsString('Board minutes April', $list, 'একটা কাগজের অধিকারে পাশের গোপন কাগজও খুলে গেল।');

        $this->actingAs($reader)->get(route('documents.show', $granted))->assertOk();
        // ⓘ নামানোর চাবি নেই, কিন্তু এই কাগজে নামানোর অধিকার আছে
        $this->actingAs($reader)->get(route('documents.download', $granted))->assertOk();
        // ⓘ ছাপার অধিকার দেওয়া হয়নি
        $this->assertContains($this->actingAs($reader)->get(route('documents.print', $granted))->getStatusCode(), [403, 404]);
        $this->assertContains($this->actingAs($reader)->get(route('documents.show', $other))->getStatusCode(), [403, 404]);
        $this->useCompany();

        $this->assertTrue(
            AuditTrail::query()->forRecord(Document::class, $granted->id)->where('action', 'doc_access_changed')->exists(),
            'অধিকার দেওয়ার কথা অডিটে নেই।',
        );

        $grant = DocumentGrant::query()->where('document_id', $granted->id)->firstOrFail();
        $this->actingAs($this->owner)->delete(route('documents.grant.destroy', [$granted, $grant]))->assertRedirect();
        $this->useCompany();

        $this->assertShut($reader, $granted, 'Board minutes March');
    }

    public function test_a_grant_to_a_role_reaches_everyone_holding_it(): void
    {
        $document = $this->upload('Auditor letter', DocumentCatalog::HIGHLY_CONFIDENTIAL);
        $reader = $this->person('role-reader@abos.test', self::READER);

        $this->assertShut($reader, $document, 'Auditor letter');

        $role = Role::query()->create(['name' => 'doc-auditors', 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $reader->assignRole($role);

        $this->actingAs($this->owner)
            ->post(route('documents.grant.store', $document), [
                'grantee_type' => DocumentGrant::ROLE,
                'grantee_id' => $role->id,
            ])
            ->assertSessionHasNoErrors();
        $this->useCompany();

        $this->assertStringContainsString('Auditor letter', $this->listFor($reader), 'ভূমিকার অধিকারে কাগজ খোলেনি।');
    }

    public function test_a_grant_never_opens_the_branch_wall(): void
    {
        $document = $this->upload('Mymensingh tender', DocumentCatalog::CONFIDENTIAL);
        $clerk = $this->person('ntk-granted@abos.test', self::READER, limitedTo: $this->netrakona);

        $this->actingAs($this->owner)
            ->post(route('documents.grant.store', $document), [
                'grantee_type' => DocumentGrant::USER,
                'grantee_id' => $clerk->id,
                'abilities' => ['download', 'print'],
            ])
            ->assertSessionHasNoErrors();
        $this->useCompany();

        $this->assertSame(0, Notification::query()->withoutGlobalScopes()->where('user_id', $clerk->id)->count(),
            'অন্য শাখার মানুষ কাগজ-ধরে অধিকারের খবর পেলেন — খবরে কাগজের নাম যায়।');
        $this->assertShut($clerk, $document, 'Mymensingh tender');
    }

    public function test_a_grant_cannot_name_someone_from_another_company(): void
    {
        $document = $this->upload('Local deed');
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        $outsider = $this->person('grant-outsider@abos.test', self::READER, company: $other);

        $this->actingAs($this->owner)
            ->post(route('documents.grant.store', $document), [
                'grantee_type' => DocumentGrant::USER,
                'grantee_id' => $outsider->id,
            ])
            ->assertSessionHasErrors('grantee_id');
        $this->useCompany();

        $this->assertSame(0, DocumentGrant::query()->count(), 'অন্য কোম্পানির মানুষকে অধিকার দেওয়া গেল।');
    }

    public function test_a_department_limit_shows_only_that_department_and_papers_with_none(): void
    {
        $sales = $this->department('SAL', 'Sales');
        $hr = $this->department('HRD', 'Human resources');

        $this->upload('Sales target sheet', extra: ['department_id' => $sales->id]);
        $hrPaper = $this->upload('Salary grid', extra: ['department_id' => $hr->id]);
        $this->upload('Company calendar');

        $clerk = $this->person('sales-clerk@abos.test', self::READER);

        $before = $this->listFor($clerk);
        $this->assertStringContainsString('Salary grid', $before, 'সীমা ছাড়াই অন্য বিভাগের কাগজ নেই — ছাঁকনি সবাইকে আটকাচ্ছে।');

        // ⓘ খোঁজের দাবি ঠিকানায় — আগে দেখে নিই ঠিকানাটা সত্যিই সারিতে বসে
        $found = (string) $this->actingAs($clerk)->get(route('documents.search', ['content' => 'Salary']))->assertOk()->getContent();
        $this->assertStringContainsString(route('documents.show', $hrPaper), $found, 'খোঁজে কাগজের ঠিকানা নেই — নিচের "নেই" দাবি অর্থহীন হত।');
        $this->useCompany();

        $this->useCompany();
        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $clerk->id,
            'scope_type' => DocumentAccess::DEPARTMENT_SCOPE,
            'scope_id' => $sales->id,
        ]);
        app(DataScope::class)->forget();

        $after = $this->listFor($clerk);
        $this->assertStringContainsString('Sales target sheet', $after, 'নিজের বিভাগের কাগজ নেই।');
        $this->assertStringContainsString('Company calendar', $after, 'বিভাগহীন কাগজ লুকিয়ে গেছে।');
        $this->assertStringNotContainsString('Salary grid', $after, 'অন্য বিভাগের কাগজ দেখা যায়।');

        $this->assertContains($this->actingAs($clerk)->get(route('documents.show', $hrPaper))->getStatusCode(), [403, 404]);
        $this->assertContains($this->actingAs($clerk)->get(route('documents.preview', $hrPaper))->getStatusCode(), [403, 404]);

        $search = (string) $this->actingAs($clerk)->get(route('documents.search', ['content' => 'Salary']))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('documents.show', $hrPaper), $search, 'বিস্তারিত খোঁজে অন্য বিভাগের কাগজ এল।');
    }

    public function test_a_document_under_review_is_not_edited_in_place(): void
    {
        $document = $this->upload('Pending policy');
        $document->forceFill(['status' => DocumentCatalog::UNDER_REVIEW])->saveQuietly();

        $this->actingAs($this->owner)
            ->put(route('documents.update', $document), [
                'name' => 'Changed while signing',
                'doc_type' => 'policy',
                'folder' => 'company',
                'branch_id' => $this->main->id,
                'confidentiality' => DocumentCatalog::INTERNAL,
            ])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), ['file' => $this->pdf('p.pdf', 'sneaky')])
            ->assertForbidden();

        $this->useCompany();
        $this->assertSame('Pending policy', $document->fresh()->name);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function department(string $code, string $name): Department
    {
        $this->useCompany();

        return Department::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'is_active' => true,
        ]);
    }

    private function listFor(User $user): string
    {
        $html = (string) $this->actingAs($user)->get(route('documents.index'))->assertOk()->getContent();
        $this->useCompany();

        return $html;
    }

    private function assertShut(User $user, Document $document, string $name): void
    {
        /*
         * ⓘ অধিকার থাকার সময়ে আসা খবর (ঘণ্টিতে নামসহ) ইতিহাস — তখন তিনি সত্যিই দেখতেন। দাবি পাতার
         * নিজের অংশের, তাই পুরনো খবর সরিয়ে দেখা। ⛔ যে খবর কখনো যাওয়ারই কথা নয় (অন্য শাখা), সেটা
         * [[test_a_grant_never_opens_the_branch_wall]] নিজে আলাদা করে দেখে।
         */
        if ($this->name() !== 'test_a_grant_never_opens_the_branch_wall') {
            Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->delete();
        }

        $this->assertStringNotContainsString($name, $this->listFor($user), $name.' তালিকায় দেখা যায়।');

        foreach (['documents.show', 'documents.download', 'documents.preview', 'documents.print'] as $door) {
            $status = $this->actingAs($user)->get(route($door, $document))->getStatusCode();
            $this->assertContains($status, [403, 404], $door.' খোলা ('.$status.')।');
        }

        // ⓘ খোঁজের ঘরে নামটা নিজেই লেখা থাকে, তাই দাবি কাগজের ঠিকানায়
        $search = (string) $this->actingAs($user)->get(route('documents.search', ['q' => $name]))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('documents.show', $document), $search, $name.' বিস্তারিত খোঁজে দেখা যায়।');

        $this->useCompany();
    }
}
