<?php

declare(strict_types=1);

return [
    'title' => 'Trade Promotion',
    'subtitle' => 'What is running, what is coming, and what has quietly run out',

    'active' => 'Running',
    'active_hint' => 'Applies to bills written today',

    'upcoming' => 'Coming up',
    'upcoming_hint' => 'Approved, not started yet',

    /* A warning, not a count — above zero means something forgot to run */
    'lapsed' => 'Past its end date',
    'lapsed_hint' => 'The dates ended, but the status still says running',
];
