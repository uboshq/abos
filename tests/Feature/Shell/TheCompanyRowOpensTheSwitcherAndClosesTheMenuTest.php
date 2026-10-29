<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Tests\TestCase;

/**
 * ⭐ ছবির মেনুর "কোম্পানি বদল" সারি বারের কোম্পানি-সুইচারটাই খোলে, আর মেনু নিজে বন্ধ হয় (ওয়েবের মান ধাপ ২, ২০ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে এক হ্যান্ডলারে দুই কাজ ছিল — `open = false; $dispatch(...)`। CSP-Alpine `;`-জোড়া কাজ পড়ে না, তাই লাইভে (CSP চালু)
 * সারিটায় চাপলে কিছুই হত না (Mac-এর csp-expressions.test.js, ২১ অক্টোবর ২০২৬)। এখন সারি শুধু ঘটনা পাঠায়, আর মেনু সেই
 * একই ঘটনা শুনে বন্ধ হয় — দুইটাই এক-কাজের এক্সপ্রেশন।
 */
final class TheCompanyRowOpensTheSwitcherAndClosesTheMenuTest extends TestCase
{
    public function test_the_row_sends_one_event_and_the_menu_closes_on_it(): void
    {
        $menu = (string) file_get_contents(resource_path('views/components/shell/profile-menu.blade.php'));
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $menu) ?? $menu;

        $this->assertMatchesRegularExpression('/data-menu-company\s+@click="\$dispatch\(\'open-company-switcher\'\)"/', $code,
            '⛔ কোম্পানির সারি সুইচার খোলার ঘটনা একা পাঠায় না।');
        $this->assertMatchesRegularExpression('/<div x-data="\{ open: false \}"[^>]*@open-company-switcher\.window="open = false"/', $code,
            '⛔ সুইচার খুললেও ছবির মেনু খোলা থেকে যায়।');

        $switcher = (string) file_get_contents(resource_path('views/components/shell/company-switcher.blade.php'));
        $this->assertStringContainsString('@open-company-switcher.window="open = true"', $switcher,
            'প্রস্তুতিটাই ভুল — সুইচার এই ঘটনা আর শোনে না।');
    }
}
