<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ সই সেই তথ্যেরও, যে তথ্যে চাওয়া হয়েছিল — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[DocumentApproval::stopping()]])।
 *
 * ⓘ সম্পদ বিদায়ের সই চাওয়া হয় "৫০,০০০ টাকা, ব্যাংক খাতে, ১৩ তারিখে, বিক্রি" তথ্যে; শেষ সই ঠিক এগুলো দিয়েই কাজটা শেষ করে।
 * ⛔ শেষ করা আটকালে হাতে আবার চাপলে আগে কেবল কাগজের ছাপ আর অঙ্ক মেলানো হত — অন্য খাত, অন্য তারিখ বা "হারানো" দিয়ে
 * পুরনো সইয়েই পার হত।
 *
 * দাবি:
 *  - একই তথ্য — একই সই চলে (সংখ্যা ৫০০০০ আর ৫০০০০.০০০০ একই);
 *  - অন্য খাত — নতুন সই, আর নতুন অনুরোধ পুরনোটার কথা বলে;
 *  - তথ্য রাখার আগের পুরনো সই — আগের মতোই চলে।
 */
final class ASignatureCoversTheValuesItWasAskedWithTest extends TestCase
{
    use RefreshDatabase;

    private DocumentApproval $guard;

    private User $manager;

    private Branch $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(DocumentApproval::class);

        $company = Company::create(['code' => 'SGV', 'name_en' => 'Signed Values Co']);
        CompanyContext::set($company->id);

        $salesman = User::create(['name' => 'Writer', 'email' => 'w@sgv.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'm@sgv.test', 'password' => 'x']);

        $flow = ApprovalFlow::create(['module' => 'accounts', 'action' => 'fixed_asset_dispose', 'threshold_amount' => null]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id]);

        $this->actingAs($salesman);
        $this->document = Branch::create(['code' => 'D'.uniqid(), 'name_en' => 'Asset']);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_same_values_keep_the_signature_and_another_account_asks_again(): void
    {
        $asked = $this->ask(['amount' => '50000', 'into_account_id' => 7, 'on' => '2026-10-13', 'as' => 'sold']);
        $this->assertNotNull($asked);
        app(ApprovalEngine::class)->approve($asked, $this->manager);

        $this->assertNull($this->ask(['amount' => '50000.0000', 'into_account_id' => '7', 'on' => '2026-10-13', 'as' => 'sold']),
            '⛔ একই তথ্যে দেওয়া সই আর চলছে না — সইয়ের পরে কাজটা কখনো শেষ হত না।');

        $again = $this->ask(['amount' => '50000', 'into_account_id' => 9, 'on' => '2026-10-13', 'as' => 'sold']);

        $this->assertNotNull($again, '⛔ ব্যাংক খাতে টাকা ঢোকার সই অন্য খাতে টাকা ঢোকাল।');
        $this->assertSame(Approval::PENDING, $again->status);
        $this->assertNotSame((int) $asked->id, (int) $again->id);
        $this->assertSame((int) $asked->id, (int) ($again->payload['supersedes'] ?? 0), '⛔ নতুন অনুরোধ বলে না কোন সইয়ের জায়গা নিল।');

        // ⓘ নতুন সই "বিক্রি"-র — "হারানো" তাতে পার হয় না
        app(ApprovalEngine::class)->approve($again, $this->manager);
        $this->assertNull($this->ask(['amount' => '50000', 'into_account_id' => 9, 'on' => '2026-10-13', 'as' => 'sold']));
        $this->assertNotNull($this->ask(['amount' => '50000', 'into_account_id' => 9, 'on' => '2026-10-13', 'as' => 'lost']),
            '⛔ "বিক্রি"-র সই "হারানো" পার করল।');
    }

    public function test_a_signature_from_before_the_values_were_kept_still_counts(): void
    {
        $asked = $this->ask(['amount' => '50000', 'into_account_id' => 7]);
        app(ApprovalEngine::class)->approve($asked, $this->manager);

        // ⓘ তথ্য রাখার আগের যুগের সারি — কেবল ঘরের নাম, মান নেই
        DB::table('approvals')->where('id', $asked->id)->update(['payload' => json_encode(['fields_seen' => ['name_en']])]);

        $this->assertNull($this->ask(['amount' => '50000', 'into_account_id' => 9]),
            '⛔ পুরনো সই হঠাৎ বাতিল — লাইভে আগে থেকে সই-হওয়া কাজ ডিপ্লয়ের দিন আবার সই চাইত।');
    }

    /** @param  array<string, mixed>  $values */
    private function ask(array $values): ?Approval
    {
        return $this->guard->stopping($this->document->fresh(), 'accounts', 'fixed_asset_dispose', '50000', payload: $values);
    }
}
