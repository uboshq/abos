<?php

declare(strict_types=1);

return [
    'no_steps' => 'A rule needs at least one level — with nobody in it, every request would wait forever.',
    'first_level_missing' => 'The first level must be level 1 — every request starts there, and with nobody on it the request would wait forever.',
    'duplicate_step' => 'The same approver cannot sit twice on the same level.',
    'duplicate_flow' => 'A rule for this action already exists — a second one would sit there doing nothing while the first kept firing. Edit the existing one.',
    'unknown_action' => 'No module asks for approval on this action, so the rule would never fire.',
    'flow_has_pending' => ':count request(s) are still waiting on this rule. Decide those first — deleting the rule would leave them with no approver.',
    'flow_change_strands' => 'This rule still has :count waiting request(s) — their levels cannot be removed, nor the action changed or the rule switched off, or nobody could sign them. Decide them first, or keep their levels in the change.',
    'limit_action_not_in_module' => 'That action does not belong to that module — the two must match, or the limit will never apply.',
];
