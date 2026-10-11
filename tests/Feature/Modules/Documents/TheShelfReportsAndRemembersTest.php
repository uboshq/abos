<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Engines\Dashboard\Stat;
use App\Models\Attachment;
use App\Models\AuditTrail;
use App\Models\User;
use App\Modules\Documents\Dashboard\DocumentsDashboard;
use App\Modules\Documents\Http\Controllers\DocumentReportController;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Models\RetentionPolicy;
use App\Modules\Documents\Services\DocumentTemplates;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * সপ্তম ধাপ — রিপোর্ট, ড্যাশবোর্ড, অডিট ট্রেইল, ছাঁচ আর রাখার নিয়ম (পরিকল্পনা §২, §৩, §১৭, §১৮, §২০)।
 *
 * ⛔ প্রতিটা গোনা আর তালিকা দেয়ালের ভিতরে — যিনি গোপন কাগজ দেখেন না, তাঁর রিপোর্ট, ড্যাশবোর্ড আর অডিটে সেটা নেই।
 */
final class TheShelfReportsAndRemembersTest extends TestCase
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

    public function test_every_report_opens_and_none_shows_what_the_viewer_may_not_see(): void
    {
        $open = $this->upload('Open price list');
        $secret = $this->upload('Secret board pay', DocumentCatalog::CONFIDENTIAL);

        foreach (array_keys(DocumentReportController::SLUGS) as $slug) {
            $this->actingAs($this->owner)->get(route('documents.report.show', $slug))->assertOk();
        }
        $this->useCompany();

        $register = fn (User $u) => (string) $this->actingAs($u)->get(route('documents.report.show', 'register'))->assertOk()->getContent();

        $this->assertStringContainsString('Secret board pay', $register($this->owner), 'মালিকের রেজিস্টারে গোপন কাগজ নেই — দাবির ভিত নেই।');
        $this->useCompany();

        $clerk = $this->person('report-clerk@abos.test', ['documents.view', 'documents.report']);
        $html = $register($clerk);
        $this->assertStringContainsString('Open price list', $html);
        $this->assertStringNotContainsString('Secret board pay', $html, '⛔ রিপোর্ট গোপন কাগজ দেখাল।');
        $this->useCompany();

        $far = $this->person('ntk-report@abos.test', ['documents.view', 'documents.report', 'documents.restricted'], limitedTo: $this->netrakona);
        $this->assertStringNotContainsString('Open price list', $register($far), '⛔ রিপোর্ট অন্য শাখার কাগজ দেখাল।');
        $this->useCompany();

        $reader = $this->person('no-report@abos.test', ['documents.view']);
        $this->actingAs($reader)->get(route('documents.report.show', 'register'))->assertForbidden();
        $this->assertNotNull($open->fresh());
    }

    public function test_the_dashboard_counts_only_what_the_viewer_sees(): void
    {
        $this->upload('Plain memo');
        $this->upload('Hidden memo', DocumentCatalog::CONFIDENTIAL);

        $total = function (User $user): string {
            $this->actingAs($user);
            $label = __('documents::dashboard.doc_total');

            return (string) collect(DocumentsDashboard::dashboard()->stats)->first(fn (Stat $s) => $s->label === $label)->value;
        };

        $this->assertSame('2', $total($this->owner));
        $this->useCompany();
        $reader = $this->person('dash-reader@abos.test', ['documents.view']);
        $this->assertSame('1', $total($reader), '⛔ ড্যাশবোর্ড গোপন কাগজ গুনল।');

        $this->useCompany();
        $this->actingAs($this->owner)->get(route('module.dashboard', ['module' => 'documents']))
            ->assertOk()->assertSee(__('documents::dashboard.doc_recent'));
    }

    public function test_the_audit_trail_shows_who_what_when_ip_and_device_and_has_no_delete_door(): void
    {
        $open = $this->upload('Audited lease');
        $secret = $this->upload('Audited secret', DocumentCatalog::CONFIDENTIAL);
        $this->actingAs($this->owner)->get(route('documents.download', $open))->assertOk();
        $this->useCompany();

        $page = (string) $this->actingAs($this->owner)->get(route('documents.audit'))->assertOk()->getContent();
        $this->assertStringContainsString('data-audit="document_downloaded"', $page);
        $this->assertStringContainsString('127.0.0.1', $page, 'অডিটে IP নেই।');
        $this->useCompany();

        $auditor = $this->person('auditor@abos.test', ['documents.view', 'documents.audit']);
        $seen = (string) $this->actingAs($auditor)->get(route('documents.audit'))->assertOk()->getContent();
        $this->assertStringContainsString('Audited lease', $seen);
        $this->assertStringNotContainsString('Audited secret', $seen, '⛔ অডিট ট্রেইল গোপন কাগজের সারি দেখাল।');
        $this->useCompany();

        $this->actingAs($this->person('no-audit@abos.test', ['documents.view']))->get(route('documents.audit'))->assertForbidden();

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'documents.')
                && in_array('DELETE', $route->methods(), true)) {
                $this->assertStringNotContainsString('audit', $route->uri(), 'অডিট মোছার দরজা আছে: '.$route->uri());
            }
        }

        $this->assertNotNull($secret->fresh());
    }

    public function test_a_template_is_filled_into_a_new_pdf_document_and_never_lets_a_value_become_markup(): void
    {
        $this->actingAs($this->owner)->post(route('documents.templates.store'), [
            'code' => 'appointment', 'title' => 'Appointment letter', 'doc_type' => 'letter', 'folder' => 'hr',
            'body' => "# নিয়োগপত্র\n\nপ্রিয় {{ person }},\n\nআপনাকে **{{ post }}** পদে নিয়োগ দেওয়া হলো — {{ company_name }}।",
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $template = DocumentTemplate::query()->where('code', 'appointment')->firstOrFail();
        $this->assertSame(['person', 'post'], app(DocumentTemplates::class)->variables($template->body));

        $this->actingAs($this->owner)->post(route('documents.templates.generate', $template), [
            'name' => 'Appointment — Rahim', 'branch_id' => $this->main->id, 'confidentiality' => DocumentCatalog::INTERNAL,
            'values' => ['person' => 'রহিম', 'post' => 'হিসাবরক্ষক'],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->useCompany();

        $document = Document::query()->where('name', 'Appointment — Rahim')->firstOrFail();
        $this->assertSame(['letter', 'hr'], [$document->doc_type, $document->folder]);
        $file = Attachment::query()->findOrFail(DocumentVersion::query()->findOrFail($document->current_version_id)->attachment_id);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($file->stored_path), 'ছাঁচ থেকে PDF হয়নি।');
        $this->assertTrue(AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_from_template')->exists());

        $html = app(DocumentTemplates::class)->html('Hello {{ who }} **x**', ['who' => '<script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script>', $html, '⛔ ঘরের মান পাতায় কোড হয়ে বসল।');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<strong>x</strong>', $html);
    }

    public function test_retention_archives_then_bins_once_and_never_deletes_for_good(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $old = $this->upload('Old delivery note');
        $pending = $this->upload('Old pending policy');
        $pending->forceFill(['status' => DocumentCatalog::SUBMITTED])->saveQuietly();

        $this->actingAs($this->owner)->post(route('documents.admin.store', 'retention'), [
            'basis' => 'created', 'archive_after_days' => 30, 'bin_after_days' => 60,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('documents.admin.store', 'retention'), [
            'basis' => 'created', 'archive_after_days' => 60, 'bin_after_days' => 30,
        ])->assertSessionHasErrors('bin_after_days');
        $this->useCompany();
        $this->assertSame(1, RetentionPolicy::query()->count());

        Carbon::setTestNow('2026-11-15 03:00:00');
        $this->artisan('abos:documents-retention')->assertSuccessful();
        $this->artisan('abos:documents-retention')->assertSuccessful();
        $this->useCompany();

        $this->assertNotNull($old->fresh()->archived_at, 'নিয়মের দিন পেরিয়েও আর্কাইভে যায়নি।');
        $this->assertSame(1, AuditTrail::query()->forRecord(Document::class, $old->id)->where('action', 'document_archived')->count(), 'দুইবার চালালে দুইবার আর্কাইভ।');
        $this->assertNull($pending->fresh()->archived_at, '⛔ অনুমোদনের মাঝের কাগজে নিয়ম হাত দিল।');

        /*
         * ⛔ বিনের ৬০ দিন গোনা আর্কাইভের দিন (১৫ নভেম্বর) থেকে, তৈরির দিন থেকে নয় (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️১৭)।
         * ⓘ ১৫ ডিসেম্বর তৈরির ৭৫ দিন, আর্কাইভের মাত্র ৩০ — আগে এখানেই বিনে যেত।
         */
        Carbon::setTestNow('2026-12-15 03:00:00');
        $this->artisan('abos:documents-retention')->assertSuccessful();
        $this->useCompany();
        $this->assertNull(Document::withTrashed()->find($old->id)?->deleted_at, '⛔ আর্কাইভের মাত্র ৩০ দিনে বিনে গেল — দিন গোনা তৈরির দিন থেকে।');

        Carbon::setTestNow('2027-01-15 03:00:00');
        $this->artisan('abos:documents-retention')->assertSuccessful();
        $this->useCompany();

        $binned = Document::withTrashed()->find($old->id);
        $this->assertNotNull($binned, '⛔ নিয়ম কাগজ চিরতরে মুছে দিল।');
        $this->assertNotNull($binned->deleted_at, 'বিনের দিন পেরিয়েও কাগজ বিনে যায়নি।');
        $this->assertSame(DocumentCatalog::DELETED, $binned->status);

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'abos:documents-retention'));
        $this->assertNotNull($event, 'রাখার নিয়ম নির্ধারিত সূচিতে নেই।');
        $this->assertTrue($event->withoutOverlapping);
    }
}
