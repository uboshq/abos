<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * ব্যাংক বা বিকাশের স্লিপ — কেবল ছবি বা PDF, আর সইকারী সেটা খুলতে পারেন (২৮ সেপ্টেম্বর ২০২৬)।
 *
 * ── যা মাপা হয় ────────────────────────────────────────────────────────
 *   ১. আসল PNG আর আসল PDF স্লিপ হিসেবে জমা হয়।
 *   ২. বাইট আর নাম না মিললে (`.php` নামে ছবি, `.jpg` নামে PDF, `.pdf` নামে লেখা)
 *      ফেরে — ঠিক `slip_wrong_kind` বার্তায়, আর কোনো সারি তৈরি হয় না।
 *   ৩. ৫ MB-র এক বাইট বেশি হলে `slip_too_big`।
 *   ৪. `kind=slip` ছাড়া সাধারণ দরজা আগের মতোই খোলা-তালিকা।
 *   ৫. নামানোর দরজা — **একই মানুষ, একই কাগজ**: সিদ্ধান্তদাতা নন → ৪০৩;
 *      অপেক্ষমাণ অনুমোদনে সিদ্ধান্তদাতা হলে → ২০০; সই হয়ে গেলে → আবার ৪০৩।
 *      আর অন্য কাগজের সংযুক্তি তখনও ৪০৩।
 *   ৬. সইয়ের পাতায় স্লিপের ছবি দেখা যায়।
 *
 * ⚠️ `source_type` হাতে লেখা হয় না — ভাউচারের পাতার ফর্ম যা পাঠায়, সেটাই পড়ে
 * নেওয়া হয় ([[pageForm()]])। নিজে নাম বসালে পাতার ভুল নাম কখনো ধরা পড়ত না।
 */
final class ABankSlipIsAPhotoOrAPdfAndTheSignerCanOpenItTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->company = Company::query()->findOrFail($this->owner->current_company_id);

        CompanyContext::set($this->company->id, $this->owner->current_branch_id
            ?? $this->company->defaultBranch()?->id);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * দাবি ১ + ২: আসল ছবি আর আসল PDF স্লিপ হিসেবে জমা হয়।
     */
    public function test_a_real_photo_and_a_real_pdf_are_kept_as_slips(): void
    {
        $voucher = $this->draftBankReceipt();
        $form = $this->pageForm($voucher);

        $this->assertSame('slip', $form['kind'], 'ব্যাংকের ভাউচারের পাতা স্লিপের দরজা দেয় না (kind=slip নেই)।');

        $png = $this->png('slip.png');
        $this->assertSame('image/png', $this->sniff($png), 'প্রস্তুতিটাই ভুল — ছবিটা আসল PNG নয়।');

        $this->postSlip($form, $png)->assertSessionHasNoErrors()->assertRedirect();

        $pdf = $this->pdf('slip.pdf');
        $this->assertSame('application/pdf', $this->sniff($pdf), 'প্রস্তুতিটাই ভুল — PDF-টা আসল নয়।');

        $this->postSlip($form, $pdf)->assertSessionHasNoErrors()->assertRedirect();

        $kept = Attachment::query()
            ->where('source_entity_id', $voucher->id)
            ->where('source_entity', $form['source_type'])
            ->pluck('original_name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['slip.pdf', 'slip.png'], $kept, 'আসল ছবি বা PDF স্লিপ হিসেবে জমা হয়নি।');
    }

    /**
     * দাবি ৩ + ৪: বাইট আর নাম না মিললে, বা ৫ MB ছাড়ালে — ফেরে, আর সারি তৈরি হয় না।
     */
    public function test_a_slip_whose_bytes_and_name_disagree_or_that_is_too_big_is_refused(): void
    {
        $voucher = $this->draftBankReceipt();
        $form = $this->pageForm($voucher);

        $wrongKind = [
            'PNG-এর বাইট, নাম .php' => $this->png('slip.php'),
            'PDF-এর বাইট, নাম .jpg' => $this->pdf('slip.jpg'),
            'লেখার ফাইল, নাম .pdf' => $this->text('slip.pdf'),
        ];

        foreach ($wrongKind as $case => $file) {
            $response = $this->postSlip($form, $file);

            $this->assertSame(
                [__('core.attachment.slip_wrong_kind')],
                $this->fileErrors($response),
                "⛔ {$case}: ঠিক 'slip_wrong_kind' বার্তায় ফেরেনি।",
            );
        }

        $big = $this->bigPdf('big-slip.pdf');
        $this->assertSame(5 * 1024 * 1024 + 1, filesize($big->getRealPath()), 'প্রস্তুতিটাই ভুল — ফাইলটা ৫ MB + ১ বাইট নয়।');

        $this->assertSame(
            [__('core.attachment.slip_too_big', ['max' => '5 MB'])],
            $this->fileErrors($this->postSlip($form, $big)),
            '⛔ ৫ MB-র বেশি স্লিপ ঠিক বার্তায় ফেরেনি।',
        );

        $this->assertSame(0, Attachment::query()->count(), '⛔ ফেরানো স্লিপের সারি তবু তৈরি হয়েছে।');
    }

    /**
     * দাবি ৫: `kind=slip` ছাড়া সাধারণ দরজা বদলায়নি — নাম আর বাইট না মিললেও চলে।
     *
     * ⓘ সাধারণ পথ নিষিদ্ধ-তালিকা; Excel নামের একটা সাধারণ লেখা সেখানে নিষিদ্ধ নয়।
     * এটা না থাকলে "সব দরজায় অনুমতি-তালিকা" বসিয়েও উপরের দাবিগুলো সবুজ হত।
     */
    public function test_without_the_slip_kind_the_ordinary_door_is_unchanged(): void
    {
        $voucher = $this->draftBankReceipt();
        $form = $this->pageForm($voucher);

        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('attachment.store'), [
                'source_type' => $form['source_type'],
                'source_id' => $form['source_id'],
                'file' => $this->text('ledger.xlsx'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, Attachment::query()->where('original_name', 'ledger.xlsx')->count(),
            '⛔ স্লিপ ছাড়া সাধারণ কাগজও এখন ফেরে — সাধারণ দরজা বদলে গেছে।');
    }

    /**
     * দাবি ৬: একই মানুষ, একই স্লিপ — কেবল অপেক্ষমাণ অনুমোদনে সিদ্ধান্তদাতা হওয়াটা বদলায়।
     */
    public function test_the_signer_opens_the_slip_only_while_the_approval_waits(): void
    {
        [$voucher, $approval] = $this->heldBankReceipt();
        $slip = $this->attachSlip($voucher, $this->png('slip.png'));

        // অন্য একটা কাগজ, যার চাবি এই মানুষের নেই
        $other = $this->draftBankReceipt();
        $otherSlip = $this->attachSlip($other, $this->pdf('other.pdf'));

        $signer = $this->keylessColleague();
        $this->assertFalse($signer->can('view', $voucher), 'প্রস্তুতিটাই ভুল — মানুষটার ভাউচার দেখার চাবি আছে।');

        $this->actingAs($signer)
            ->get(route('attachment.download', $slip))
            ->assertForbidden();

        // ⓘ কাগজটা ইনার হাতে দেওয়া — ফরওয়ার্ড যা বসায় ([[ApprovalEngine::canDecide]])
        $approval->forceFill(['assigned_to' => $signer->id])->save();

        $this->assertTrue(
            app(\App\Core\Engines\Approval\ApprovalEngine::class)->canDecide($approval->fresh(), $signer),
            'প্রস্তুতিটাই ভুল — মানুষটা এখনো সিদ্ধান্তদাতা নন।',
        );

        $this->actingAs($signer)
            ->get(route('attachment.download', $slip))
            ->assertOk();

        $this->actingAs($signer)
            ->get(route('attachment.download', $otherSlip))
            ->assertForbidden();

        // সই হয়ে গেল — ছাড়টা আর নেই
        $approval->forceFill(['status' => Approval::APPROVED, 'decided_at' => now()])->save();

        $this->actingAs($signer)
            ->get(route('attachment.download', $slip))
            ->assertForbidden();
    }

    /**
     * দাবি ৭: সইয়ের পাতায় স্লিপের ছবিটা দেখা যায়।
     */
    public function test_the_signing_page_shows_the_slip_photo(): void
    {
        [$voucher, $approval] = $this->heldBankReceipt();
        $slip = $this->attachSlip($voucher, $this->png('slip.png'));

        $signer = $this->keylessColleague();
        $approval->forceFill(['assigned_to' => $signer->id])->save();

        $body = $this->actingAs($signer)
            ->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-approval-papers', $body, '⛔ সইয়ের পাতায় কাগজের অংশই নেই।');

        $src = preg_quote(e(route('attachment.download', $slip)), '/');

        $this->assertMatchesRegularExpression(
            '/<img\b[^>]*\bsrc="'.$src.'"/',
            $body,
            '⛔ সইয়ের পাতায় স্লিপের ছবি নেই।',
        );
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /**
     * ভাউচারের পাতার কাগজ তোলার ফর্ম — ব্রাউজার যা পাঠাত।
     *
     * @return array{source_type: string, source_id: string, kind: ?string}
     */
    private function pageForm(Voucher $voucher): array
    {
        $body = $this->actingAs($this->owner)
            ->get(route('accounts.voucher.show', $voucher))
            ->assertOk()
            ->getContent();

        $action = preg_quote(e(route('attachment.store')), '/');

        $this->assertMatchesRegularExpression('/<form\b[^>]*action="'.$action.'"/', $body,
            'ভাউচারের পাতায় কাগজ তোলার ফর্ম নেই।');

        preg_match('/<form\b[^>]*action="'.$action.'"[^>]*>(.*?)<\/form>/s', $body, $form);

        preg_match('/name="source_type" value="([^"]*)"/', $form[1], $type);
        preg_match('/name="source_id" value="([^"]*)"/', $form[1], $id);
        preg_match('/name="kind" value="([^"]*)"/', $form[1], $kind);

        return [
            'source_type' => html_entity_decode($type[1] ?? ''),
            'source_id' => $id[1] ?? '',
            'kind' => $kind[1] ?? null,
        ];
    }

    /** @param array{source_type: string, source_id: string, kind: ?string} $form */
    private function postSlip(array $form, UploadedFile $file): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)
            ->from(route('accounts.voucher.show', (int) $form['source_id']))
            ->post(route('attachment.store'), [
                'source_type' => $form['source_type'],
                'source_id' => $form['source_id'],
                'kind' => $form['kind'],
                'file' => $file,
            ]);
    }

    private function attachSlip(Voucher $voucher, UploadedFile $file): Attachment
    {
        $form = $this->pageForm($voucher);

        $this->postSlip($form, $file)->assertSessionHasNoErrors();

        return Attachment::query()
            ->where('source_entity_id', $voucher->id)
            ->where('original_name', $file->getClientOriginalName())
            ->latest('id')
            ->firstOrFail();
    }

    /** @return list<string> */
    private function fileErrors(\Illuminate\Testing\TestResponse $response): array
    {
        $errors = session('errors');

        /*
         * ⓘ এই অ্যাপের session JSON-এ সংরক্ষিত হয়, তাই ভুলের থলেটা এখানে অনেক সময়
         * ViewErrorBag নয়, তার সাজানো রূপ (`default.messages.file`)। ⚠️ দুই রূপই পড়া হয় —
         * নাহলে সত্যি ফেরানো ফাইলও "কোনো ভুল নেই" দেখাত।
         */
        if ($errors instanceof ViewErrorBag) {
            return $errors->getBag('default')->get('file');
        }

        return is_array($errors) ? array_values($errors['default']['messages']['file'] ?? []) : [];
    }

    private function png(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));

        $path = $this->tempPath();
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = $this->tempPath();
        file_put_contents($path, $this->pdfBytes());

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function bigPdf(string $name): UploadedFile
    {
        $bytes = $this->pdfBytes();
        $target = 5 * 1024 * 1024 + 1;

        $path = $this->tempPath();
        file_put_contents($path, $bytes.str_repeat('%', $target - strlen($bytes)));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function text(string $name): UploadedFile
    {
        $path = $this->tempPath();
        file_put_contents($path, "This is only a line of plain text, not a slip.\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
            ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function sniff(UploadedFile $file): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'slip');
        $this->temp[] = $path;

        return $path;
    }

    /**
     * একই কোম্পানির একজন — কোনো রোল নেই, তাই ভাউচার দেখার চাবিও নেই।
     */
    private function keylessColleague(): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->owner->current_branch_id,
        ]);
        $user->companies()->attach($this->company->id);

        return $user;
    }

    /**
     * অনুমোদনে আটকানো ব্যাংকের আদায় — পর্দার "এখন পোস্ট করুন" দিয়ে।
     *
     * @return array{0: Voucher, 1: Approval}
     */
    private function heldBankReceipt(): array
    {
        $voucher = $this->draftBankReceipt();

        // ⓘ একমাত্র সইকারী অন্য একজন — পরীক্ষার মানুষটা শুরুতে সিদ্ধান্তদাতা নন
        $flow = ApprovalFlow::create([
            'company_id' => $this->company->id,
            'module' => 'accounts',
            'action' => 'receipt',
            'is_active' => true,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => User::factory()->create()->id,
        ]);

        $this->actingAs($this->owner)
            ->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher));

        $approval = Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('approvable_id', $voucher->id)
            ->pending()
            ->firstOrFail();

        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status, 'প্রস্তুতিটাই ভুল — ভাউচার আটকায়নি।');

        return [$voucher, $approval];
    }

    private function draftBankReceipt(): Voucher
    {
        $bank = $this->bankAccount();

        $other = Account::query()
            ->where('company_id', $this->company->id)
            ->postable()
            ->active()
            ->whereKeyNot($bank->id)
            ->whereNull('money_kind')
            ->orderBy('code')
            ->firstOrFail();

        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'SLIP-GUARD',
            'instrument_no' => null,
        ], [
            ['account_id' => $bank->id, 'debit' => '1200', 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => '1200'],
        ]);

        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, 'হেল্পারটা খসড়া দেয়নি।');

        return $voucher;
    }

    private function bankAccount(): Account
    {
        $existing = Account::query()
            ->where('company_id', $this->company->id)
            ->where('money_kind', Account::BANK)
            ->postable()
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $sibling = Account::query()
            ->where('company_id', $this->company->id)
            ->where('money_kind', Account::CASH)
            ->postable()
            ->firstOrFail();

        $account = $sibling->replicate(['public_id']);
        $account->forceFill([
            'code' => 'BANK-SLIP',
            'name_en' => 'BANK-SLIP',
            'name_bn' => 'BANK-SLIP',
            'money_kind' => Account::BANK,
        ])->save();

        return $account->refresh();
    }
}
