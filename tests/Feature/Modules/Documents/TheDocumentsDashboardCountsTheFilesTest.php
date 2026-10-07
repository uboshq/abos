<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Documents\Dashboard\DocumentsDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * নথির ড্যাশবোর্ড — মালিকের ড্যাশবোর্ড নকশা §১৩ (৬ অক্টোবর ২০২৬)।
 *
 * ⓘ দাবি, আগে-পরের ফারাক ধরে: আজ জোড়া দুইটা নথি → মোট +২, আজ +২, এ মাসের স্তম্ভ +২, ধরনে PDF +১ ছবি +১;
 * মুছে ফেলা নথি → কেবল ফেলে দেওয়ার ঝুড়িতে; চালু লিংক কেবল বাতিল-না-হওয়া, মেয়াদ-না-ফুরানো, বাছা শাখার।
 * ⛔ অন্য কোম্পানির নথি কোথাও গোনা হয় না। ⛔ বাতিল লিংক, মেয়াদ ফুরানো লিংক, অন্য শাখার লিংক চালু গোনা হয় না।
 */
final class TheDocumentsDashboardCountsTheFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_figure_moves_by_exactly_what_was_added(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $other = Company::query()->where('id', '<>', $company->id)->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $owner = $this->pick($company, $owner, (string) $a->id);
        $before = $this->read();

        $this->file($company, 'bill.pdf', 'application/pdf', 2048);
        $this->file($company, 'slip.jpg', 'image/jpeg', 1024);
        $this->file($company, 'gone.pdf', 'application/pdf', 512)->delete();
        $stranger = $this->file($other, 'theirs.pdf', 'application/pdf', 4096);
        $this->assertSame($other->id, (int) $stranger->company_id, 'অন্য কোম্পানির নথি বসেনি — দাবির ভিত নেই।');

        $this->share($company, $a, now()->addDays(3));                       // ✓ চালু
        $this->share($company, $a, now()->addDays(3), revoked: true);        // ⛔ বাতিল
        $this->share($company, $a, now()->subDay());                         // ⛔ মেয়াদ ফুরানো
        $this->share($company, $b, now()->addDays(3));                       // ⛔ অন্য শাখা
        $this->share($other, null, now()->addDays(3));                       // ⛔ অন্য কোম্পানি
        $this->deliver($company, $a);
        $this->deliver($company, $b);                                        // ⛔ অন্য শাখা

        $after = $this->read();

        $this->assertSame(2, $after['total'] - $before['total'], 'মোট নথি দুইটা বাড়েনি।');
        $this->assertSame(2, $after['new_today'] - $before['new_today'], 'আজকের নথি দুইটা বাড়েনি।');
        $this->assertSame(1, $after['recycled'] - $before['recycled'], 'মুছে ফেলা নথি ঝুড়িতে যায়নি।');
        $this->assertSame(1, $after['shared'] - $before['shared'], '⛔ চালু লিংক ভুল গোনা — বাতিল/মেয়াদ ফুরানো/অন্য শাখা/অন্য কোম্পানি ঢুকেছে।');
        $this->assertSame(2, $after['month_up'] - $before['month_up'], 'এ মাসের "জোড়া" স্তম্ভ দুইটা বাড়েনি।');
        $this->assertSame(1, $after['month_sent'] - $before['month_sent'], '⛔ এ মাসের "বেরোল" স্তম্ভ অন্য শাখার কাগজও গুনেছে।');
        $this->assertSame(1, $after['kind_pdf'] - $before['kind_pdf']);
        $this->assertSame(1, $after['kind_image'] - $before['kind_image']);

        // ⓘ জায়গা — নথির খাতার যোগফল থেকেই, অন্য কোম্পানি বাদ
        $bytes = (int) DB::table('attachments')->where('company_id', $company->id)->whereNull('deleted_at')->sum('size_bytes');
        $this->assertGreaterThanOrEqual(3072, $bytes);
        $this->assertSame($this->expectedSize($bytes), $after['storage']);

        // ⓘ চার্টে তারিখের লেখা আছে
        $monthly = collect(DocumentsDashboard::dashboard()->panels)->first(fn ($p) => $p instanceof Series);
        $this->assertMatchesRegularExpression('/২০২৬|2026/u', (string) $monthly->range);

        // ⓘ মেনুর পাতা খোলে
        $this->actingAs($owner)->get(route('module.dashboard', ['module' => 'documents']))
            ->assertOk()->assertSee(__('documents::dashboard.recent'));
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        app(DataScope::class)->forget();
        $board = DocumentsDashboard::dashboard();
        $stat = fn (string $key) => collect($board->stats)->first(fn (Stat $s) => $s->label === __('documents::dashboard.'.$key))->value;
        $monthly = collect($board->panels)->first(fn ($p) => $p instanceof Series);
        $kinds = collect($board->panels)->first(fn ($p) => $p instanceof Breakdown && $p->label === __('documents::dashboard.by_kind'));
        $kind = fn (string $k) => (int) collect($kinds->parts)->firstWhere('label', __('documents::dashboard.kind_'.$k))['value'];
        $points = $monthly->points;
        $last = end($points);

        return [
            'total' => (int) $stat('total'), 'new_today' => (int) $stat('new_today'), 'recycled' => (int) $stat('recycled'),
            'shared' => (int) $stat('shared'), 'storage' => $stat('storage'),
            'month_up' => (int) $last['first'], 'month_sent' => (int) $last['second'],
            'kind_pdf' => $kind('pdf'), 'kind_image' => $kind('image'),
        ];
    }

    private function expectedSize(int $bytes): string
    {
        foreach ([[1073741824, 'GB'], [1048576, 'MB'], [1024, 'KB']] as [$unit, $word]) {
            if ($bytes >= $unit) {
                $n = bcdiv((string) $bytes, (string) $unit, 1);

                return (str_ends_with($n, '.0') ? substr($n, 0, -2) : $n).' '.$word;
            }
        }

        return $bytes.' B';
    }

    private function file(Company $company, string $name, string $mime, int $bytes): Attachment
    {
        return Attachment::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'source_module' => 'sales', 'source_entity' => 'invoice', 'source_entity_id' => 1,
            'original_name' => $name, 'stored_path' => 'test/'.Str::random(12), 'mime_type' => $mime,
            'extension' => pathinfo($name, PATHINFO_EXTENSION), 'size_bytes' => $bytes, 'checksum' => Str::random(64), 'version' => 1,
        ]);
    }

    private function share(Company $company, ?Branch $branch, \DateTimeInterface $expires, bool $revoked = false): void
    {
        DB::table('doc_shares')->insert([
            'public_id' => (string) Str::ulid(), 'company_id' => $company->id, 'branch_id' => $branch?->id,
            'route_name' => 'sales.invoice.print', 'route_params' => '[]', 'document_type' => 'sales_invoice', 'document_id' => 1,
            'paper' => 'a4', 'token' => Str::random(64), 'expires_at' => $expires, 'revoked_at' => $revoked ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deliver(Company $company, Branch $branch): void
    {
        DB::table('doc_deliveries')->insert([
            'public_id' => (string) Str::ulid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
            'document_type' => 'sales_invoice', 'document_id' => 1, 'paper' => 'a4', 'how' => 'printed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pick(Company $company, User $owner, string $branch): User
    {
        $this->actingAs($owner)->post(route('branch.switch'), ['branch_id' => $branch])->assertRedirect();
        $owner = $owner->fresh();
        CompanyContext::set($company->id, $owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($owner);

        return $owner;
    }
}
