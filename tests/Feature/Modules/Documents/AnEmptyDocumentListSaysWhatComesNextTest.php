<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ডকুমেন্টের খালি তালিকা ভাগের "খালি অবস্থা" আঁকে — আর ছাঁচের খালি তালিকায় পরের কাজ, নতুন ছাঁচ (১১ অক্টোবর ২০২৬, documents রিভিউ;
 * [[x-ui.empty-state]])। ⓘ আগে খালি সারিতে কেবল একটা ধূসর লাইন।
 */
final class AnEmptyDocumentListSaysWhatComesNextTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_the_empty_lists_draw_the_shared_empty_state_and_templates_offer_the_first_one(): void
    {
        foreach (['documents.signatures', 'documents.approval', 'documents.audit'] as $route) {
            $html = (string) $this->actingAs($this->owner)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('data-msg', $html, "⛔ {$route}: খালি তালিকায় ভাগের খালি অবস্থা নেই।");
            $this->useCompany();
        }

        \App\Modules\Documents\Models\DocumentTemplate::query()->delete();
        $templates = (string) $this->actingAs($this->owner)->get(route('documents.templates'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('documents.templates.create'), '/').'"[^>]*data-empty-action/', $templates,
            '⛔ ছাঁচের খালি তালিকায় "নতুন ছাঁচ" পরের কাজ নেই।');
    }
}
