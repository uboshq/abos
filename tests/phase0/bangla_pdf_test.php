<?php

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Phase 0 — ঝুঁকি পরীক্ষা ১: mPDF বাংলা যুক্তাক্ষর রেন্ডারিং।
 *
 * প্ল্যানের সেকশন ২ বলে DomPDF নয়, mPDF — কারণ DomPDF বাংলা যুক্তাক্ষর ভাঙে।
 * এই স্ক্রিপ্ট সেটা প্রমাণ করে: কঠিন যুক্তাক্ষরগুলো একটা ইনভয়েসের মতো করে ছাপে।
 *
 * চালানো:  php tests/phase0/bangla_pdf_test.php
 */

require __DIR__.'/../../vendor/autoload.php';

$out = __DIR__.'/output';
if (! is_dir($out)) {
    mkdir($out, 0777, true);
}

// যেসব যুক্তাক্ষরে DomPDF ভাঙে — একটাও ভাঙলে পরীক্ষা ব্যর্থ
$conjuncts = [
    'ক্ষ' => 'ক্ষুদ্র ব্যবসা',
    '্র' => 'প্রতিষ্ঠান, ক্রয়, বিক্রয়',
    '্য' => 'ব্যাংক হিসাব, ন্যূনতম',
    'ঙ্ক' => 'অঙ্ক, শঙ্কা',
    'ৎ' => 'উৎপাদন, তৎক্ষণাৎ',
    '্ব' => 'স্বাক্ষর, দ্বিতীয়',
    'ঞ্চ' => 'পঞ্চম, সঞ্চয়',
    'ষ্ট' => 'কষ্ট, স্পষ্ট',
    'ন্ত' => 'অন্তর্ভুক্ত, শান্ত',
    'দ্ধ' => 'বৃদ্ধি, শুদ্ধ',
    'হ্ম' => 'ব্রাহ্মণ, চিহ্ন',
    'র্ব' => 'সর্বমোট, পূর্ব',
];

$rows = '';
foreach ($conjuncts as $c => $sample) {
    $rows .= "<tr><td class=\"c\">{$c}</td><td>{$sample}</td></tr>";
}

$numbers = '১২৩৪৫৬৭৮৯০';
$money = '১,২৫,০০০.০০';

$html = <<<HTML
<style>
    body { font-family: hindsiliguri; font-size: 11pt; }
    h1 { font-size: 16pt; margin: 0 0 2mm 0; }
    .sub { color: #475569; font-size: 9pt; margin-bottom: 6mm; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
    th, td { border: 0.2mm solid #cbd5e1; padding: 1.6mm 2mm; text-align: left; }
    th { background: #0d47a1; color: #fff; font-size: 10pt; }
    .c { font-size: 14pt; width: 22mm; }
    /* হিসাবের কলাম: ইংরেজি অঙ্ক + tabular ফন্ট, যাতে কলাম মেলে (প্ল্যান সেকশন ১৮.৪) */
    .num { font-family: dejavusans; text-align: right; }
    /* বাংলা অঙ্ক DejaVu-তে নেই — বাংলা অঙ্ক ছাপতে হলে বাংলা ফন্টই লাগবে */
    .num-bn { font-family: hindsiliguri; text-align: right; }
    .note { font-size: 9pt; color: #475569; }
</style>

<h1>ABOS — বাংলা রেন্ডারিং পরীক্ষা</h1>
<div class="sub">Phase 0 · যুক্তাক্ষর, সংখ্যা ও টেবিল · mPDF + Hind Siliguri</div>

<table>
    <tr><th colspan="2">কঠিন যুক্তাক্ষর</th></tr>
    {$rows}
</table>

<table>
    <tr><th>বিবরণ</th><th>পরিমাণ</th><th>দর</th><th>মোট</th></tr>
    <tr><td>ব্যাংক হিসাব খোলার ফি</td><td class="num">2</td><td class="num">5,000.00</td><td class="num">10,000.00</td></tr>
    <tr><td>ক্ষুদ্র যন্ত্রাংশ (প্রতিষ্ঠানভিত্তিক)</td><td class="num">15</td><td class="num">1,250.00</td><td class="num">18,750.00</td></tr>
    <tr><td>পরিবহন ও সংরক্ষণ</td><td class="num">1</td><td class="num">3,500.00</td><td class="num">3,500.00</td></tr>
    <tr><th colspan="3">সর্বমোট</th><th class="num">32,250.00</th></tr>
</table>

<table>
    <tr><th colspan="2">সংখ্যার নিয়ম (সেকশন ১৮.৪)</th></tr>
    <tr><td>টাকার অঙ্ক — সবসময় ইংরেজি, tabular ফন্টে</td><td class="num">1,25,000.00</td></tr>
    <tr><td>বাংলা অঙ্ক — শুধু বাংলা ফন্টে ছাপা যায়</td><td class="num-bn">{$money}</td></tr>
    <tr><td>বাংলা অঙ্ক tabular ফন্টে দিলে যা হয়</td><td class="num">{$money}</td></tr>
</table>

<p class="note">
    বাংলা সংখ্যা: {$numbers}<br>
    কথায়: এক লক্ষ পঁচিশ হাজার টাকা মাত্র। &nbsp;·&nbsp; স্বাক্ষর ____________
</p>
HTML;

$sizes = [
    'A4' => ['format' => 'A4', 'margin' => 12],
    '80mm' => ['format' => [80, 250], 'margin' => 3],
    '58mm' => ['format' => [58, 250], 'margin' => 2],
];

$fail = 0;
foreach ($sizes as $label => $cfg) {
    try {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $cfg['format'],
            'margin_left' => $cfg['margin'],
            'margin_right' => $cfg['margin'],
            'margin_top' => $cfg['margin'],
            'margin_bottom' => $cfg['margin'],
            'tempDir' => sys_get_temp_dir(),
            'fontDir' => array_merge(
                (new ConfigVariables)->getDefaults()['fontDir'],
                [__DIR__.'/../../storage/fonts']
            ),
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'hindsiliguri' => [
                    'R' => 'HindSiliguri-Regular.ttf',
                    'B' => 'HindSiliguri-Bold.ttf',
                    'useOTL' => 0xFF,   // যুক্তাক্ষরের জন্য OpenType Layout — এটা ছাড়া ভাঙবে
                    'useKashida' => 75,
                ],
                'notosansbengali' => [
                    'R' => 'NotoSansBengali-Regular.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font' => 'hindsiliguri',
        ]);

        $mpdf->SetTitle('ABOS — বাংলা রেন্ডারিং পরীক্ষা');
        $mpdf->WriteHTML($html);

        $file = "{$out}/bangla-{$label}.pdf";
        $mpdf->Output($file, Destination::FILE);

        printf("OK    %-5s  %s  (%d bytes)\n", $label, basename($file), filesize($file));
    } catch (Throwable $e) {
        printf("FAIL  %-5s  %s\n", $label, $e->getMessage());
        $fail++;
    }
}

exit($fail === 0 ? 0 : 1);
