<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ডকুমেন্টের ফাইল তোলার বোতাম বাংলায় — ব্রাউজারের "Choose File" নয় (১১ অক্টোবর ২০২৬, documents রিভিউ ⛔৫; [[x-ui.file-input]])।
 *
 * ⓘ তোলার ফর্ম, নতুন ভার্সন আর স্ক্যানের ক্যামেরা — তিনটাই কাঁচা `<input type="file">` ছিল, আর AFileIsChosenInBengaliTest ব্রাঞ্চেই লাল।
 * ⚠️ স্ক্যানের দেখার বোতাম জমা দেয় না (`form` এমন ফর্মের যা নেই) — নইলে ছবিগুলো আসল ঘরের সাথে দুইবার যেত।
 */
final class TheFileButtonsSpeakBengaliTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_upload_new_version_and_scan_draw_the_bengali_button_with_the_real_box_inside(): void
    {
        $upload = (string) $this->actingAs($this->owner)->get(route('documents.create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-file-input.*?<input type="file" id="files" name="files\[\]" class="sr-only"[^>]*multiple[^>]*required/su', $upload,
            '⛔ তোলার ফর্মে বাংলা বোতাম নেই, বা আসল ঘর `files[]` হারাল।');

        $document = $this->upload();
        $show = (string) $this->actingAs($this->owner)->get(route('documents.show', $document))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-file-input.*?<input type="file" id="version-file" name="file" class="sr-only"/su', $show,
            '⛔ নতুন ভার্সনের ঘরে বাংলা বোতাম নেই।');

        $scan = (string) $this->actingAs($this->owner)->get(route('documents.scan'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="file" id="scan-pick" name="scan_pick\[\]" class="sr-only"[^>]*form="no-such-form"[^>]*x-on:change="addPages"/su', $scan,
            '⛔ স্ক্যানের বোতাম বাংলা নয়, জমা দেয়, বা পাতা জোড়ে না।');
        $this->assertStringContainsString('name="pages[]"', $scan, 'ⓘ আসল জমার ঘর হারাল।');
    }
}
