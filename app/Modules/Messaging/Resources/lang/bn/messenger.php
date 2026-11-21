<?php

declare(strict_types=1);

return [
    'title' => 'বার্তা',
    'group_subtitle' => 'গ্রুপ · :count জন',
    'file_required' => 'ফাইলটা আসেনি — আবার পাঠান।',
    'bad_target' => 'কাকে পাঠাবেন, বোঝা গেল না।',
    'too_big' => 'ফাইলটা বড় — সর্বোচ্চ :mb MB।',
    'bad_type' => 'এই ধরনের ফাইল পাঠানো যায় না।',
    'deleted' => 'এই বার্তাটি মুছে ফেলা হয়েছে',
    'client_id_used' => 'এই বার্তার চিহ্নটা অন্য কথোপকথনে আগেই ব্যবহার হয়েছে।',
    'kind' => [
        'image' => 'ছবি',
        'file' => 'ফাইল',
        'voice' => 'ভয়েস',
        'location' => 'অবস্থান',
    ],
    'system' => [
        'members_added' => ':actor :names-কে গ্রুপে যোগ করেছেন',
        'group_created' => ':actor গ্রুপ ":title" বানিয়েছেন',
    ],
    'refused' => [
        'forbidden' => 'এই কথোপকথনে আপনি নেই।',
        'may_not_talk' => 'আপনার রোলে এঁকে বার্তা বা কল দেওয়ার অনুমতি নেই।',
        'group_create_denied' => 'গ্রুপ বানানোর অনুমতি আপনার নেই।',
        'paper_denied' => 'এই কাগজ খোলার অনুমতি আপনার নেই, তাই পাঠানো যাবে না।',
        'delete_not_allowed' => 'সবার জন্য মোছা যায় কেবল নিজের বার্তা, পাঠানোর এক ঘণ্টার মধ্যে।',
        'call_over' => 'কলটি আর বাজছে না — শেষ হয়ে গেছে।',
        'calls_off' => 'কল এখনো চালু করা হয়নি।',
    ],
    'call' => [
        'incoming_voice' => 'ABOS ভয়েস কল আসছে…',
        'incoming_video' => 'ABOS ভিডিও কল আসছে…',
        'missed' => 'মিসড কল — :name',
    ],
];
