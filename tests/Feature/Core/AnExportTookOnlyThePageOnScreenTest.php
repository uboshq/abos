<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রপ্তানিতে যেত কেবল পর্দার পাতাটা — ৩০ সেপ্টেম্বর ২০২৬ (নিরাপত্তা-অডিট ২৯ সেপ্টেম্বরের খোলা খোঁজ)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * তালিকাগুলো পাতা ভাগ করে (৫০-৬০ সারি), আর রপ্তানি নিত কেবল যা আঁকা হলো। বোতামের নিচে
 * লেখা থাকত "এই পাতায় যা দেখছেন" — কিন্তু মাস শেষে তালিকা নামানো মানুষ চান পুরো মাস।
 * ১২০ সারির তালিকায় ফাইলে ৬০টা, আর মোটের সাথে কিছুই মিলত না।
 *
 * ── ⭐ কীভাবে মাপা ──────────────────────────────────────────────────────
 * নিরীক্ষার খাতা (পাতায় ৬০) — তিন পাতার মতো সারি বসিয়ে CSV নামানো; ফাইলে সবগুলো,
 * একবারই করে, আর দ্বিতীয় পাতায় দাঁড়িয়ে নামালেও শুরু থেকে।
 */
final class AnExportTookOnlyThePageOnScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        for ($i = 1; $i <= 150; $i++) {
            AuditTrail::query()->create([
                'company_id' => $this->company->id,
                'branch_id' => $this->company->defaultBranch()?->id,
                'user_id' => $this->owner->id,
                'action' => 'updated',
                'auditable_type' => User::class,
                'auditable_id' => $this->owner->id,
                'document_no' => sprintf('EXP-%04d', $i),
                'label' => 'Probe',
            ]);
        }
    }

    public function test_the_file_holds_every_page_not_just_the_one_on_screen(): void
    {
        $expected = AuditTrail::query()->count();
        $this->assertGreaterThan(120, $expected, 'প্রস্তুতিটাই ভুল — দুই পাতার বেশি সারি নেই।');

        foreach ([null, 2] as $standingOn) {
            $query = array_filter(['export' => 'csv', 'page' => $standingOn], fn ($v) => $v !== null);
            $csv = $this->actingAs($this->owner)->get(route('governance.audit.index', $query))->assertOk();

            $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));

            $body = $csv->getContent();
            $lines = array_values(array_filter(explode("\r\n", ltrim($body, "\xEF\xBB\xBF")), fn ($l) => $l !== ''));

            $this->assertCount($expected + 1, $lines,
                '⛔ ফাইলে সব সারি নেই (পাতা '.($standingOn ?? 1).' থেকে নামানো) — কেবল পর্দার পাতাটা গেছে।');

            foreach (['EXP-0001', 'EXP-0075', 'EXP-0150'] as $no) {
                $this->assertSame(1, substr_count($body, $no), "⛔ {$no} ফাইলে ঠিক একবার নেই — পাতা জোড়ায় বাদ বা দুইবার।");
            }
        }
    }

    public function test_the_screen_itself_still_shows_one_page(): void
    {
        $page = $this->actingAs($this->owner)->get(route('governance.audit.index'))->assertOk();

        $this->assertCount(60, $page->viewData('trails')->items(), 'পর্দার পাতা-ভাগ বদলে গেছে — এটা বদলানোর কথা নয়।');
    }
}
