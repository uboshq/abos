<?php

declare(strict_types=1);

/*
 * Which staff member handles which dealer — ⛔16, 2 October 2026 ([[DealerBindingController]]).
 */
return [
    'menu' => 'Dealer bindings',
    'title' => 'Who handles which dealer',
    'subtitle' => 'A salesman sees only the dealers bound to him',
    'setting' => 'A salesman sees only his own dealers',

    'switch_on' => 'On: salesmen see only their bound dealers. No binding means nothing.',
    'switch_off' => 'Off: everyone sees every dealer.',

    'preview' => 'How many dealers each person will see',
    'preview_hint' => 'Red means: a salesman with no dealer bound — he will see nothing.',
    'sees_count' => 'Sees :count dealers',
    'sees_all' => 'Sees every dealer',
    'under' => 'Reports to: :name',

    'bind' => 'Bind dealers',
    'staff' => 'Staff member',
    'dealers' => 'Dealers (pick several)',
    'or_area' => 'Or every dealer of one area',
    'area_hint' => 'Only the dealers in that area today. A dealer who joins later must be bound separately.',
    'starts_on' => 'From',
    'bind_button' => 'Bind',

    'handover' => 'Handover',
    'handover_hint' => 'All dealers of the old person move to the new one. The new person sees the old bills too. Whose sale it was does not change.',
    'from' => 'From',
    'to' => 'To',
    'on_date' => 'New person from',
    'handover_button' => 'Hand over',

    'supervisor' => 'Supervisor',
    'supervisor_hint' => 'A supervisor sees the dealers of everyone under him (SM · TSM · RSM · DSM).',
    'supervisor_of' => 'Supervisor',
    'no_supervisor' => 'Nobody',

    'list' => 'Bindings',
    'everyone' => 'Everyone',
    'with_ended' => 'Include ended ones',
    'none' => 'No bindings.',
    'from_date' => 'From: :date',
    'until_date' => 'Until: :date',
    'still_on' => 'Still running',
    'end_button' => 'End',

    'tree' => 'Who reports to whom',
    'tree_hint' => 'Each person sees the dealers of everyone under him',
    'tree_link' => 'Who reports to whom',
    'tree_empty' => 'No supervisor has been set yet.',

    'bound' => ':count new bindings made.',
    'ended' => 'Binding ended.',
    'handed_over' => ':count dealers changed hands.',
    'supervisor_saved' => 'Supervisor saved.',

    'need_dealers' => '⛔ Pick at least one dealer or an area.',
    'unknown_dealer' => '⛔ One of the chosen dealers is not in this company.',
    'unknown_area' => '⛔ That area was not found.',
    'area_empty' => '⛔ That area has no active dealer today.',
    'unknown_staff' => '⛔ This staff member is not in this company.',
    'end_before_start' => '⛔ The end day cannot be before the start day.',
    'same_person' => '⛔ A handover needs two different people.',
    'nothing_to_hand_over' => '⛔ This staff member has no running binding.',
    'supervisor_loop' => '⛔ Nobody can report to himself or to someone under him.',
    'bad_date' => '⛔ That date could not be read.',
    'office_takes_money' => '⛔ Only the office takes money — a collection cannot be sent from the phone. Hand the money in at the office.',
    'dealer_out_of_reach' => '⛔ This dealer is not bound to you — you cannot place his order.',
];
