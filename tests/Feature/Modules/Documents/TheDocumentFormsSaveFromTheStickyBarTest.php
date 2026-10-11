<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ডকুমেন্টের ফর্মগুলো বাতিল · সংরক্ষণ নিচের স্থির পট্টিতে রাখে, আর নিজের বিশেষ বোতাম (ব্যস্ত, বন্ধ) হারায় না
 * (১১ অক্টোবর ২০২৬, documents রিভিউ; [[x-ui.form-actions]]-এর `submit` slot)।
 */
final class TheDocumentFormsSaveFromTheStickyBarTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_upload_scan_and_template_pages_carry_the_bar_with_their_own_submit(): void
    {
        $upload = $this->page(route('documents.create'));
        $this->assertMatchesRegularExpression('/data-form-actions.*?href="'.preg_quote(route('documents.index'), '/').'".*?type="submit"[^>]*:class="busy/su', $upload,
            '⛔ তোলার ফর্মে পট্টি নেই, বা ব্যস্ত-অবস্থার বোতাম হারাল।');
        $this->assertSame(1, substr_count($this->between($upload, 'action="'.route('documents.store').'"', '</form>'), 'type="submit"'), 'সংরক্ষণ একটার বেশি।');

        $scan = $this->page(route('documents.scan'));
        $this->assertMatchesRegularExpression('/data-form-actions.*?type="submit"[^>]*:disabled="busy \|\| ! pages.length"/su', $scan,
            '⛔ স্ক্যানের পট্টিতে "পাতা না থাকলে বন্ধ" বোতাম নেই।');
    }

    private function page(string $url): string
    {
        return (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
    }

    private function between(string $html, string $from, string $to): string
    {
        $at = strpos($html, $from);
        $this->assertNotFalse($at, "⛔ পাতায় «{$from}» নেই।");

        return substr($html, $at, (int) strpos($html, $to, $at) - $at);
    }
}
