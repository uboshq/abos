<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Services\DataScope;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * একটা কাগজ তিন দেয়ালের ভিতরে — কোম্পানি, শাখা, গোপনীয়তা (পরিকল্পনা §১৩, §১৪)।
 *
 * ── ⛔ প্রতিটা পড়ার পথ ─────────────────────────────────────────────────
 * তালিকা, বিস্তারিত, নামানো, প্রিভিউ — চারটা পথ, প্রতিটা দেয়ালের জন্য আলাদা করে।
 * ⚠️ একটা পথ বাদ গেলে ফাঁকটা থাকত ঠিক সেখানেই, যেটা কেউ পরীক্ষা করেনি।
 *
 * ── ⓘ দাবির নিয়ম ─────────────────────────────────────────────────────
 * একই মানুষ, একই কাগজ — কেবল নাগাল বা চাবি বদলায়: বাইরে থাকলে বন্ধ, ভিতরে এলে খোলা।
 * ⚠️ নাহলে "বন্ধ" দাবিটা একটা ভাঙা দরজাতেও সবুজ হত।
 */
final class ADocumentStaysBehindItsWallsTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    /** ⓘ পড়ার সব চাবি — কেবল গোপনীয়তার ধাপ বাদে */
    private const READER = ['documents.view', 'documents.download', 'documents.print'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_user_of_another_company_cannot_reach_the_document(): void
    {
        $document = $this->upload('Our secret tender');

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        $outsider = $this->person('outsider@abos.test', [...self::READER, 'documents.restricted'], company: $other);

        $this->assertShut($outsider, $document, 'Our secret tender', 'অন্য কোম্পানির মানুষ');
    }

    public function test_a_user_limited_to_another_branch_cannot_reach_it_until_the_branch_is_theirs(): void
    {
        $document = $this->upload('Mymensingh lease');

        $clerk = $this->person('ntk-clerk@abos.test', self::READER, limitedTo: $this->netrakona);

        $this->assertShut($clerk, $document, 'Mymensingh lease', 'অন্য শাখায় সীমিত মানুষ');

        // ⓘ একই মানুষ — এবার প্রধান শাখাও নাগালে
        $this->useCompany();
        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $this->main->id,
        ]);
        app(DataScope::class)->forget();

        $this->assertOpen($clerk, $document, 'Mymensingh lease', 'শাখা নাগালে আসার পরেও');
    }

    public function test_a_confidential_document_is_hidden_from_someone_without_the_right(): void
    {
        $document = $this->upload('Board salaries', DocumentCatalog::CONFIDENTIAL);

        $reader = $this->person('reader@abos.test', self::READER);

        $this->assertShut($reader, $document, 'Board salaries', 'গোপন ধাপের চাবি ছাড়া মানুষ');

        // ⓘ একই মানুষ — এবার গোপনের চাবি
        $this->useCompany();
        Permission::findOrCreate('documents.confidential', 'web');
        $reader->givePermissionTo('documents.confidential');

        $this->assertOpen($reader, $document, 'Board salaries', 'গোপনের চাবি পাওয়ার পরেও');
    }

    public function test_a_higher_step_is_not_opened_by_a_lower_key(): void
    {
        $document = $this->upload('Restricted land deed', DocumentCatalog::RESTRICTED);

        $reader = $this->person('confidential@abos.test', [...self::READER, 'documents.confidential']);

        $this->assertShut($reader, $document, 'Restricted land deed', 'কেবল গোপনের চাবিওয়ালা মানুষ');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function assertShut(User $user, Document $document, string $name, string $who): void
    {
        $list = (string) $this->actingAs($user)->get(route('documents.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($name, $list, $who.' সেন্টারের তালিকায় কাগজটা দেখেন।');

        foreach (['documents.show', 'documents.download', 'documents.preview', 'documents.print'] as $door) {
            $status = $this->actingAs($user)->get(route($door, $document))->getStatusCode();
            $this->assertContains($status, [403, 404], $who.' '.$door.' দিয়ে কাগজে ঢুকলেন ('.$status.')।');
        }

        $this->useCompany();
    }

    private function assertOpen(User $user, Document $document, string $name, string $who): void
    {
        $list = (string) $this->actingAs($user)->get(route('documents.index'))->assertOk()->getContent();
        $this->assertStringContainsString($name, $list, $who.' তালিকায় কাগজ নেই — দেয়াল সবাইকে আটকাচ্ছে।');

        $this->actingAs($user)->get(route('documents.show', $document))->assertOk();
        $this->actingAs($user)->get(route('documents.download', $document))->assertOk();
        $this->actingAs($user)->get(route('documents.preview', $document))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->useCompany();
    }
}
