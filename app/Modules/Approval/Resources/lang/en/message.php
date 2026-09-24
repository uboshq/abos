<?php

declare(strict_types=1);

return [
    'coverage_on' => 'Required',
    'coverage_off' => 'Switched off',
    'coverage_none' => 'Not set up',
    'coverage_count' => ':on / :all',
    'coverage_note' => 'Where approval can be required, and where it is set up in this company. With no rule, nothing is stopped — and no screen says so.',
    'approved' => 'Approved.',
    'rejected' => 'Sent back.',
    'withdrawn' => 'The request was withdrawn.',
    'flow_saved' => 'The rule was saved.',
    'flow_deleted' => 'The rule was deleted.',

    'nothing_waiting' => 'Nothing is waiting for your decision.',

    'inbox_capped' => 'Showing the :shown oldest; :total are waiting in all.',
    'no_requests' => 'You have not asked for an approval yet.',
    'no_match' => 'No rule matches this search — try another word, or clear it to see the whole list.',
    'no_flows' => 'No rules are set up — so nothing needs approval anywhere.',
    'no_flows_hint' => 'Without a rule, discounts, cancellations and back-dated entries all go through unasked.',

    'threshold_hint' => 'Leave it empty and every one needs approval. Ask the owner to sign off a 50-taka discount and nobody follows the rule — and once it is skipped, the whole thing is decoration.',
    'remarks_hint' => 'So that in six months someone can still find the reason — who asked for it, and after what.',
    'document_gone' => 'The document is no longer there.',

    // The amount is the one from the day it was asked for; the paper may have moved since.
    'changed_since_asked' => 'The document was changed after this was asked for — the amount above is the one from then. Open the document before you sign.',

    // The same paper has come round twice, and why — otherwise it reads as a mistake.
    'supersedes' => 'This is not new work — it was signed at :was, then the document changed, so it needs signing again.',
    'supersedes_plain' => 'This is not new work — it was signed once, then the document changed, so it needs signing again.',
    'document_not_yours' => 'The document is there, but you do not have the permission to open it. What you see here is the approval record — who asked, at which level, and who decided what.',
    'awaiting' => 'Waiting for approval — the request has been sent.',
    'level_of' => 'Level :current of :total',
    'not_your_turn' => 'You are not in the rule for this level.',
    'own_request' => 'You cannot approve your own request.',

    /*
     * A counting report has nothing to click — and it says so.
     *
     * Every other report's row is a document, so people learn to click.
     * Here nothing would happen, and they would take the page for broken.
     * So the limit and the way round it go in one line.
     */
    'report_counts_only' => 'These are counts, so there is nothing to open on a row. To see a particular request, go to "Waiting for me", or open the pending / approved / turned-down report — every row there opens.',
    'forwarded' => 'Passed on to :name.',
    'delegation_saved' => 'Delegation set — it expires on its own.',
    'delegation_revoked' => 'Delegation stopped. The row is kept: who signed for whom is a question that comes up later.',
    'delegation_none' => 'You have not handed your signature to anyone yet.',
    'delegation_note' => 'Hand your approvals to someone before you go on leave. It expires on its own — nothing to switch off when you return.',
    'delegation_held_none' => 'Nobody has handed you theirs.',
    'bulk_done' => 'Signed :count.',
    'bulk_skipped_money' => ':count left out — money moves there, so each one is signed on its own.',
    'bulk_skipped_not_allowed' => ':count left out — beyond your authority.',
    'bulk_skipped_not_pending' => ':count left out — already settled.',
    'bulk_none' => 'Nothing selected.',
    'sla_hint' => 'Leave empty for no clock on this step — behaviour stays as it was.',
    'escalate_hint' => 'A time limit with no destination goes nowhere; the exceptions screen flags it.',
    'conditions_hint' => 'The field name must match exactly what the module sends, or this flow never catches.',
    'min_approvals_hint' => 'How many signatures at the same level move it on. Empty means one.',
    'sla_fine' => 'In time',
    'sla_near' => 'Running out',
    'sla_late' => 'Past due',
    'sla_escalated' => 'Passed upward',
    'sla_due' => 'Due :when',
    'bulk_only_paper' => 'Work that moves money cannot be signed in a batch — those are opened one at a time.',
    'bulk_confirm' => 'The selected papers will be signed. Go on?',
    'limit_note' => 'With more than one role the highest ceiling applies — adding a role never takes power away. Within one role, the most specific row wins.',
    'max_amount_hint' => 'Leave empty for no ceiling on this row. Do not enter zero — that would stop this role signing any amount at all.',
    'no_limits' => 'No limits are set — anyone named in a flow can sign any amount.',
    'limit_saved' => 'The limit was set.',
    'limit_removed' => 'The limit was taken away.',
    'limit_remove_confirm' => 'Without this limit the role is no longer held to any amount. Go on?',
    'step_now' => 'Here now',
    'step_waiting' => 'Waiting',
    'step_of' => ':signed of :needed',
    'approve_confirm' => 'This signs off :amount. Are you sure?',
    'approve_confirm_plain' => 'This signs off the paper. Are you sure?',
    'coverage_hole' => 'Clock set, nowhere to go — step :levels',
    'send_back_hint' => 'For example — the challan date is wrong, the buyer name does not match',
    'fix_and_resend' => 'Fix the paper and confirm it again — amending it asks for a fresh approval on its own.',
    'step_took' => ':hours h',
    'beyond_your_authority' => 'It is your turn, but the amount is past your ceiling — someone above you has to sign.',
];
