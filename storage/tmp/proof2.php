<?php
use Illuminate\Support\Facades\Blade;

$tpl = '<x-ui.table :columns="[[\'label\' => \'Name\']]">'
     . '<tr><td>SENTINEL-ROW</td></tr>'
     . '</x-ui.table>';

$out = Blade::render($tpl);

echo "স্লটের সারিটা এসেছে? " . (str_contains($out, 'SENTINEL-ROW') ? 'হ্যাঁ' : 'না — গিলে ফেলেছে') . "\n";
echo "খালি-অবস্থা এসেছে? " . (str_contains($out, 'empty') || str_contains($out, 'svg') ? 'হ্যাঁ' : 'না') . "\n";
echo "মোট দৈর্ঘ্য: " . strlen($out) . " অক্ষর\n";
