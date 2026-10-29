<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentLink;
use App\Modules\Documents\Services\DocumentSignatures;
use App\Modules\Documents\Services\DocumentWorkflow;
use App\Modules\MasterData\Models\Location;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ডকুমেন্টের নম্বরের পাশে পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে
 * গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"* ([[DocumentParties]])
 *
 * ⛔ আগে ডকুমেন্ট সেন্টার, অনুমোদনের সারি আর সই কেন্দ্রে কেবল নম্বর আর কাগজের নাম — কার চুক্তি, বোঝা যেত না।
 *
 * ⭐ দাবি: গ্রাহকের সাথে জোড়া কাগজের সারিতে নম্বর আর "নাম · পয়েন্ট", সরবরাহকারীর সাথে জোড়া কাগজে সরবরাহকারী — সেন্টার,
 * অনুমোদনের সারি ("আমার পাঠানো") আর সই কেন্দ্র ("আমার চাওয়া") তিন পর্দায়; ফোনের দুই দরজার সারিতে আলাদা `party` ঘর; ২১টা
 * জোড়া কাগজের সেন্টারে গ্রাহকের টেবিলে প্রশ্ন বাড়ে না।
 */
final class EveryDocumentNumberNamesItsPartyTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    private const POINT = 'কারওয়ান বাজার';

    private Customer $shop;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        app(SettingsService::class)->set(PhoneModules::key('documents'), true);

        $point = Location::query()->create([
            'company_id' => $this->company->id, 'parent_id' => null, 'code' => 'PT-DOC',
            'name_en' => 'Kawran Bazar', 'name_bn' => self::POINT, 'level' => Location::POINT, 'is_active' => true,
        ]);
        $this->shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->shop->forceFill(['location_id' => $point->id])->save();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    public function test_the_centre_the_approval_queue_and_the_signature_centre_name_the_party_beside_the_number(): void
    {
        $agreement = $this->linked('Dealer agreement', 'customer', $this->shop->id);
        $contract = $this->linked('Supply contract', 'supplier', $this->supplier->id);
        foreach ([$agreement, $contract] as $document) {
            $this->askFor($document, DocumentWorkflow::ACTION);
            $this->askFor($document, DocumentSignatures::ACTION);
        }

        $pages = [
            'ডকুমেন্ট সেন্টার' => route('documents.index'),
            'অনুমোদনের সারি' => route('documents.approval', ['mine' => 1]),
            'সই কেন্দ্র' => route('documents.signatures', ['tab' => 'asked']),
        ];

        foreach ($pages as $page => $url) {
            $html = $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
            $this->useCompany();

            $this->assertStringContainsString(e($this->shop->name().' · '.self::POINT), $this->rowOf($html, $agreement->document_no),
                "⛔ {$page}: গ্রাহকের সাথে জোড়া কাগজের নম্বরের সারিতে গ্রাহক · পয়েন্ট নেই।");
            $this->assertStringContainsString(e($this->supplier->name()), $this->rowOf($html, $contract->document_no),
                "⛔ {$page}: সরবরাহকারীর সাথে জোড়া কাগজের সারিতে সরবরাহকারী নেই।");
        }
    }

    public function test_the_phone_rows_carry_the_party(): void
    {
        $agreement = $this->linked('Dealer agreement', 'customer', $this->shop->id);
        $this->askFor($agreement, DocumentWorkflow::ACTION);
        $this->askFor($agreement, DocumentSignatures::ACTION);

        foreach (['/api/v1/documents/approvals?mine=1', '/api/v1/documents/signatures?tab=asked'] as $url) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
            $row = collect($this->getJson($url)->assertOk()->json('rows'))->firstWhere('document_no', $agreement->document_no);
            $this->useCompany();

            $this->assertNotNull($row, "⛔ {$url}: কাগজটা সারিতে নেই।");
            // ⓘ ১৯ অক্টোবর ২০২৬ থেকে শাখাও — "নাম · পয়েন্ট · শাখা" (মালিকের স্থায়ী নিয়ম; [[PartyRegistry::labelsOf()]])
            $this->assertSame($this->shop->fresh()->load(Customer::PLACE)->nameWithPoint(), $row['party'] ?? null, "⛔ {$url}: সারিতে আলাদা `party` ঘর নেই।");
            $this->assertStringStartsWith($this->shop->name().' · '.self::POINT, (string) ($row['party'] ?? ''), "⛔ {$url}: সারির পক্ষে পয়েন্ট নেই।");
        }
    }

    public function test_a_centre_of_twenty_one_linked_documents_does_not_ask_the_customers_table_per_row(): void
    {
        $customers = Customer::query()->orderBy('id')->limit(7)->get();
        $this->linked('Linked 0', 'customer', $customers[0]->id);
        $one = $this->queriesOfTheCentre();

        for ($i = 1; $i < 21; $i++) {
            $this->linked('Linked '.$i, 'customer', $customers[$i % $customers->count()]->id);
        }
        $many = $this->queriesOfTheCentre();

        $this->assertSame($this->asked($one, 'customers'), $this->asked($many, 'customers'),
            '⛔ এক থেকে একুশটা জোড়া কাগজে গ্রাহকের টেবিলে প্রশ্ন বেড়েছে — সারি ধরে নাম তোলা (N+1)।');
        $this->assertSame($this->asked($one, 'dms_document_links'), $this->asked($many, 'dms_document_links'),
            '⛔ জোড়াগুলো সারি ধরে তোলা হচ্ছে।');
    }

    // ── সাহায্যকারী ──────────────────────────────────────────────────────

    private function linked(string $name, string $type, int $id): Document
    {
        $document = $this->upload($name);

        DocumentLink::query()->create([
            'company_id' => $this->company->id, 'document_id' => $document->id,
            'source_type' => $type, 'source_id' => $id, 'created_by' => $this->owner->id,
        ]);

        return $document;
    }

    private function askFor(Document $document, string $action): Approval
    {
        return Approval::query()->create([
            'company_id' => $this->company->id,
            'approvable_type' => Document::class,
            'approvable_id' => $document->id,
            'module' => DocumentWorkflow::MODULE,
            'action' => $action,
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->owner->id,
            'requested_at' => now(),
        ]);
    }

    /** পাতার যে সারিতে নম্বরটা আছে — `<tr` ধরে কাটা */
    private function rowOf(string $html, string $needle): string
    {
        // ⓘ নম্বরটা পাতার অন্য জায়গাতেও থাকতে পারে (জমার বার্তা) — তাই পক্ষের ঘর-ওয়ালা সারি আগে
        $pieces = array_values(array_filter(explode('<tr', $html), fn (string $piece) => str_contains($piece, $needle)));

        foreach ($pieces as $piece) {
            if (str_contains($piece, 'data-party')) {
                return $piece;
            }
        }

        if ($pieces !== []) {
            return $pieces[0];
        }

        $this->fail("⛔ পাতায় {$needle}-এর সারিই নেই।");
    }

    /** @return list<string> */
    private function queriesOfTheCentre(): array
    {
        $this->actingAs($this->owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('documents.index'))->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->useCompany();

        return $queries;
    }

    /** @param list<string> $queries */
    private function asked(array $queries, string $table): int
    {
        return count(array_filter($queries, fn (string $sql) => preg_match('/\bfrom\s+[`"]?'.$table.'[`"]?(\s|$)/i', $sql) === 1));
    }
}
