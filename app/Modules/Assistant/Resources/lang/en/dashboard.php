<?php

declare(strict_types=1);

// ⭐ Copilot dashboard — six charts (owner, 20 Nov 2026)
return [
    'title' => ':name dashboard',
    'subtitle' => 'How many questions, how many were understood and answered, when and who asks — from the Copilot log.',
    'today' => 'Today',
    'per_day' => 'Questions per day — 30 days',
    'per_day_hint' => ':range · asked against answered',
    'asked' => 'Asked',
    'understood' => 'Understood',
    'answered' => 'Answered',
    'liked' => 'Got a 👍',
    'funnel' => 'Asked → understood → answered',
    'funnel_hint' => ':range · answer rate on the right',
    'by_module' => 'Answers by area of work',
    'by_module_hint' => ':range · by the module that answered',
    'answers' => 'answers',
    'other_tool' => 'Other',
    'who_asks' => 'Who asks most',
    'who_asks_hint' => ':range · questions per person',
    'my_searches' => 'My most searched',
    'my_searches_hint' => ':range · in the top search box, your own only',
    'when' => 'When questions are asked',
    'when_hint' => ':range · weekday × time of day',
    'parts' => 'Which parts are used most',
    'parts_hint' => ':range · by log kind, page opens left out',
    'weekdays' => [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'],
    'bands' => ['Morning', 'Midday', 'Afternoon', 'Evening', 'Night'],
];
