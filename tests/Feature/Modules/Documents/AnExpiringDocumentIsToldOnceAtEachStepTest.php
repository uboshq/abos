<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\AuditTrail;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Models\ExpiryNotice;
use App\Modules\Documents\Services\DocumentNotices;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * মেয়াদের খবর — ৯০ → ৬০ → ৩০ → ১৫ → ৭ → ১ দিন, প্রতিটা একবার; আর খবর কখনো দেয়াল পেরোয় না
 * (পরিকল্পনা §১২, §২৩; তৃতীয় ধাপ)।
 */
final class AnExpiringDocumentIsToldOnceAtEachStepTest extends TestCase
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

    public function test_each_step_is_told_once_however_often_the_command_runs(): void
    {
        Carbon::setTestNow('2026-10-09 09:00:00');
        $keeper = $this->person('licence-keeper@abos.test', ['documents.view']);

        $document = $this->upload('Trade licence 2026', extra: ['expiry_date' => '2026-10-29', 'owner_id' => $keeper->id]);
        $this->upload('Far away lease', extra: ['expiry_date' => '2027-06-01', 'owner_id' => $keeper->id]);

        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->useCompany();

        // ⓘ ২০ দিন বাকি — কেবল ৩০-এর খবর, পেরিয়ে যাওয়া ৯০ আর ৬০ নয়; আর দুইবার চালালেও একবার
        $this->assertSame([30], ExpiryNotice::query()->where('document_id', $document->id)->pluck('threshold')->all());
        $this->assertSame(1, $this->notes($keeper, DocumentNotices::RENEWAL_REQUIRED), 'একই খবর দুইবার গেল।');
        $this->assertSame(0, $this->notes($keeper, DocumentNotices::EXPIRY_WARNING), '৯০ দিনের বাইরের কাগজেও খবর।');

        Carbon::setTestNow('2026-10-15 09:00:00'); // ১৪ দিন বাকি
        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->artisan('abos:documents-expiry')->assertSuccessful();

        Carbon::setTestNow('2026-10-31 09:00:00'); // পেরিয়ে গেছে
        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->useCompany();

        $this->assertSame([30, 15, 0], ExpiryNotice::query()->where('document_id', $document->id)->orderBy('id')->pluck('threshold')->all());
        $this->assertSame(2, $this->notes($keeper, DocumentNotices::RENEWAL_REQUIRED));
        $this->assertSame(1, $this->notes($keeper, DocumentNotices::EXPIRED), '"মেয়াদ শেষ" একবার যায়নি।');
        $this->assertSame(DocumentCatalog::EXPIRED, $document->fresh()->status, 'পেরোনো কাগজ "মেয়াদোত্তীর্ণ" হয়নি।');
        $this->assertSame(1, AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_expired')->count());

        // ⭐ নবায়ন — নতুন মেয়াদ বসালে কাগজ খসড়া, আর খবরের চক্র নতুন করে
        $this->actingAs($this->owner)->put(route('documents.update', $document), [
            'name' => 'Trade licence 2026', 'doc_type' => 'contract', 'folder' => 'contracts',
            'branch_id' => $this->main->id, 'confidentiality' => DocumentCatalog::INTERNAL,
            'owner_id' => $keeper->id, 'expiry_date' => '2027-11-05',
        ])->assertSessionHasNoErrors();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::DRAFT, $document->fresh()->status, 'নবায়নের পরেও মেয়াদোত্তীর্ণ।');
    }

    public function test_a_warning_never_reaches_someone_who_cannot_see_the_document(): void
    {
        Carbon::setTestNow('2026-10-09 09:00:00');
        $reader = $this->person('no-secrets@abos.test', ['documents.view']);

        // ⓘ গোপন কাগজ, মালিক এমন কেউ যাঁর গোপনের চাবি নেই — তবু নিজের কাগজ তিনি দেখেন, খবরও পান
        $own = $this->upload('Own secret permit', DocumentCatalog::CONFIDENTIAL, extra: ['expiry_date' => '2026-10-12', 'owner_id' => $reader->id]);

        // ⓘ অন্যের গোপন কাগজে অধিকার দেওয়া হলো, তারপর সরানো — সরানোর খবরে নাম নেই
        $secret = $this->upload('Board secret', DocumentCatalog::CONFIDENTIAL);
        $this->actingAs($this->owner)->post(route('documents.grant.store', $secret), [
            'grantee_type' => DocumentGrant::USER, 'grantee_id' => $reader->id,
        ])->assertSessionHasNoErrors();
        $this->useCompany();
        $grant = DocumentGrant::query()->where('document_id', $secret->id)->firstOrFail();
        $this->actingAs($this->owner)->delete(route('documents.grant.destroy', [$secret, $grant]))->assertRedirect();
        $this->useCompany();

        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->useCompany();

        $titles = Notification::query()->withoutGlobalScopes()->where('user_id', $reader->id)->pluck('title')->all();

        $this->assertTrue(collect($titles)->contains(fn ($t) => str_contains($t, 'Own secret permit')), 'নিজের কাগজের মেয়াদের খবর আসেনি।');
        $this->assertSame(2, collect($titles)->filter(fn ($t) => str_contains($t, $secret->document_no) || str_contains($t, 'Board secret'))->count(),
            'অধিকার দেওয়া আর সরানো — দুইটা খবর হওয়ার কথা।');
        $removed = Notification::query()->withoutGlobalScopes()->where('user_id', $reader->id)
            ->where('type', DocumentNotices::PERMISSION_CHANGED)->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('Board secret', $removed->title, 'অধিকার সরানোর খবরে গোপন কাগজের নাম।');
        $this->assertNull($removed->url, 'অধিকার সরানোর খবরে কাগজের লিংক।');
        $this->assertNotNull($own->fresh());
    }

    public function test_the_command_is_on_the_schedule_and_never_overlaps(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'abos:documents-expiry'));

        $this->assertNotNull($event, 'মেয়াদের কাজ নির্ধারিত সূচিতে নেই।');
        $this->assertTrue($event->withoutOverlapping, 'মেয়াদের কাজ একসাথে দুইবার চলতে পারে।');
        $this->assertSame('35 * * * *', $event->expression, 'মেয়াদের কাজ ঘণ্টায় একবার চলে না।');
    }

    private function notes(User $user, string $type): int
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', $type)->count();
    }
}
