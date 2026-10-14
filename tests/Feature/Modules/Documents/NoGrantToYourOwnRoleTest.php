<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Support\CompanyContext;
use App\Modules\Documents\Models\DocumentGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ নিজের ভূমিকাকে কাগজ-ধরে অধিকার নয় — Documents লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ ১১ অক্টোবরের পাহারা কেবল "নিজের নামে" দেওয়া থামাত। অধিকার দেওয়ার চাবি থাকা একজন নিজের ভূমিকায় নামানো আর বদলের অধিকার
 * দিলে ভূমিকার সদস্য হিসেবে নিজেই সেটা পেয়ে যেতেন — নামানোর চাবি না থাকলেও কাগজ নামাতেন।
 */
final class NoGrantToYourOwnRoleTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_grant_to_a_role_you_hold_is_refused_and_another_role_still_works(): void
    {
        $document = $this->upload('Price list');

        $clerk = $this->person('granter@abos.test', ['documents.view', 'documents.permissions']);
        $mine = Role::query()->create(['name' => 'Desk Clerks', 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $other = Role::query()->create(['name' => 'Field Team', 'guard_name' => 'web', 'company_id' => $this->company->id]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->assignRole($mine));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->useCompany();

        $this->actingAs($clerk->fresh())->post(route('documents.grant.store', $document), [
            'grantee_type' => DocumentGrant::ROLE, 'grantee_id' => $mine->id, 'abilities' => ['download', 'edit'],
        ])->assertSessionHasErrors(['grantee_id' => __('documents::message.not_to_your_role')]);
        $this->useCompany();
        $this->assertSame(0, DocumentGrant::query()->where('grantee_type', DocumentGrant::ROLE)->where('grantee_id', $mine->id)->count(),
            '⛔ নিজের ভূমিকায় অধিকার বসল — নিজেকেই নামানোর অধিকার');
        $this->actingAs($clerk->fresh())->get(route('documents.download', $document))->assertForbidden();

        // ⓘ অন্য ভূমিকাকে — আগের মতোই
        $this->actingAs($clerk->fresh())->post(route('documents.grant.store', $document), [
            'grantee_type' => DocumentGrant::ROLE, 'grantee_id' => $other->id, 'abilities' => ['download'],
        ])->assertSessionDoesntHaveErrors('grantee_id');
    }
}
