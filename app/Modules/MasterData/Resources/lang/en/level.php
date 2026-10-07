<?php

declare(strict_types=1);

/*
 * ⓘ দেখানো নাম আর কোডের নাম আলাদা — ১৯ সেপ্টেম্বর ২০২৬।
 *
 * মালিক বললেন: Area-র নাম "Region", আর Territory-র নাম "Area"। ⚠️ কেবল
 * **লেখা** বদলেছে, চাবি নয় — `area` / `territory` ডেটাবেসের `level`
 * কলামে বসা আছে, সেটিংসের চাবিতে, আর রিপোর্টের ছাঁকনিতে। চাবি বদলালে
 * লাইভের সারিগুলোর মানে বদলে যেত।
 *
 * ⚠️ `region` চাবিটা (এখন বন্ধ স্তর) আগে "Region" লিখত। ওটা এখন "Zone" —
 * নাহলে ঐ স্তর চালু করলে পর্দায় দুইটা "Region" পাশাপাশি বসত।
 */
return [
    'country' => 'Country',
    'division' => 'Division',
    'region' => 'Zone',
    'area' => 'Region',
    'territory' => 'Area',
    'point' => 'Point',
    'route' => 'Route',
];
