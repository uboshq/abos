<?php

declare(strict_types=1);

return [
    'approved' => 'Approved.',
    'rejected' => 'Sent back.',
    'withdrawn' => 'The request was withdrawn.',
    'flow_saved' => 'The rule was saved.',
    'flow_deleted' => 'The rule was deleted.',

    'nothing_waiting' => 'Nothing is waiting for your decision.',
    'no_requests' => 'You have not asked for an approval yet.',
    'no_flows' => 'No rules are set up — so nothing needs approval anywhere.',
    'no_flows_hint' => 'Without a rule, discounts, cancellations and back-dated entries all go through unasked.',

    'threshold_hint' => 'Leave it empty and every one needs approval. Ask the owner to sign off a 50-taka discount and nobody follows the rule — and once it is skipped, the whole thing is decoration.',
    'document_gone' => 'The document is no longer there.',
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
];
