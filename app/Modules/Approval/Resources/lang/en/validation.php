<?php

declare(strict_types=1);

return [
    'no_steps' => 'A rule needs at least one level — with nobody in it, every request would wait forever.',
    'duplicate_step' => 'The same approver cannot sit twice on the same level.',
    'duplicate_flow' => 'A rule for this action already exists — a second one would sit there doing nothing while the first kept firing. Edit the existing one.',
    'unknown_action' => 'No module asks for approval on this action, so the rule would never fire.',
    'flow_has_pending' => ':count request(s) are still waiting on this rule. Decide those first — deleting the rule would leave them with no approver.',
];
