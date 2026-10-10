<?php

declare(strict_types=1);

return [
    'title' => 'Escalation management',
    'note' => 'Deadline, level and responsible person for pending approvals. Reminding and escalating are done by the approval engine itself, by each step\'s time limit — this page only shows it. Escalating is not approving.',
    'whole_company' => 'This page covers the whole company — it does not open for a branch-limited user.',
    'state' => 'State',
    'filter' => ['waiting' => 'Within time', 'late' => 'Past the deadline', 'escalated' => 'Escalated'],
    'states' => ['fine' => 'Within time', 'near' => 'Deadline close', 'late' => 'Past the deadline', 'escalated' => 'Escalated', 'none' => '—'],
    'paper' => 'Request',
    'level' => 'Level',
    'responsible' => 'Responsible',
    'deadline' => 'Deadline',
    'reminded' => 'Reminded',
    'escalated_to' => 'Escalated to',
    'holes' => ':count steps have a time limit but no one to escalate to — when their time runs out, nobody will be told.',
    'empty' => 'No approvals with a time limit are waiting now.',
];
