<?php

declare(strict_types=1);

return [
    'field' => 'Sales channel',
    'hint' => 'The road this customer buys through. Every document keeps the channel of the day it was sold — changing it later does not rewrite past sales.',
    'none' => 'Not set',
    'invalid' => 'This channel is not in this company\'s list, or it is inactive.',
];
