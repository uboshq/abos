<?php

declare(strict_types=1);

return [
    'format_needs_sequence' => 'নম্বরের ছকে {SEQ} থাকতেই হবে — ওটা না থাকলে প্রতিটা ডকুমেন্ট একই নম্বর পেত।',
    'code_required' => 'কোড দিতে হবে।',
    'code_taken' => ':code কোডে আরেকটা রেকর্ড আছে।',
    'unknown_level' => 'এলাকার স্তরটা ঠিক নয়।',
    'level_disabled' => ':level স্তরটা এই প্রতিষ্ঠানে বন্ধ। সেটিংস থেকে চালু করুন।',
    'level_cannot_change' => 'এলাকার স্তর বদলানো যায় না — নিচের সব এলাকা তখন ভুল জায়গায় পড়ত। নতুন একটা তৈরি করে পুরনোটা নিষ্ক্রিয় করুন।',
    'parent_required' => 'উপরের :level বাছতে হবে।',
    'parent_not_found' => 'উপরের এলাকাটা পাওয়া গেল না।',
    'wrong_parent_level' => 'উপরে :expected থাকার কথা, :given নয়।',
    'parent_cannot_be_own_descendant' => 'একটা এলাকা নিজের নিচে বসতে পারে না।',
    'default_cannot_deactivate' => 'ডিফল্ট রেকর্ড নিষ্ক্রিয় করা যাবে না — আগে অন্য একটাকে ডিফল্ট করুন।',
    'unit_cycle' => 'একক নিজের ভিত্তি হতে পারে না, ঘুরেও নয়।',
    'factor_must_be_positive' => 'রূপান্তরের সংখ্যা শূন্যের বেশি হতে হবে।',
    'rate_out_of_range' => 'করের হার ০ থেকে ১০০-র মধ্যে হতে হবে।',
    'top_level_has_no_parent' => ':level সবচেয়ে উপরের স্তর — এর উপরে কিছু বসে না।',
    'base_currency_has_no_rate' => 'ভিত্তি মুদ্রার হার হয় না — নিজের সাপেক্ষে হার সবসময় ১।',
    'rate_must_be_positive' => 'হার শূন্যের বেশি হতে হবে।',
];
