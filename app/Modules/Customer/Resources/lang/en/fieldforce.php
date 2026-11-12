<?php

declare(strict_types=1);

/*
 * Field force tree (owner, 12 Nov 2026; [[FieldForceController]]).
 */
return [
    'menu' => 'Field force',
    'title' => 'Field force — who reports to whom, which areas and depots',
    'legend_dsm' => 'all areas, everyone below',
    'legend_rsm' => 'region',
    'legend_asm' => 'area',
    'legend_sr' => 'own dealers',

    'wall' => 'Wall',
    'wall_on' => 'On',
    'wall_off' => 'Off',
    'wall_hint' => 'When on, the field force sees only their own (and their team\'s) dealers and depots. Make everything below green first.',
    'wall_turn_on' => 'Turn the wall on',
    'wall_turn_off' => 'Turn the wall off',
    'wall_owner_only' => 'Only the owner turns the wall on or off',
    'wall_not_ready' => '⛔ The wall was not turned on — someone has no depot or would see no dealer. Make the list green first.',
    'wall_saved_on' => 'The wall is on.',
    'wall_saved_off' => 'The wall is off — everyone sees every dealer.',

    'positioned' => 'with a position',
    'no_depot' => 'without a depot — would see nothing',
    'no_boss' => 'without a supervisor (except DSM)',
    'blind' => 'would see no dealer',
    'loose' => 'dealers belong to nobody',

    'tree' => 'Tree',
    'add_person' => '+ Add a person',
    'loose_people' => 'Under nobody (:count)',
    'add_under' => '+ Add under :name',
    'no_depot_chip' => 'No depot',
    'areas_count' => 'Areas: :count',
    'dealers' => ':count dealers',
    'below_all' => ':count dealers below',
    'tree_hint' => 'To move someone under another person: pick them and change "Reports to" on the right — the tree rearranges itself. A supervisor sees every dealer below, however deep.',
    'tree_empty' => 'Nobody yet. People with a field role (SR, ASM, RSM, DSM) appear here by themselves; use "Add a person" for anyone else.',

    'pick' => 'Pick someone in the tree — their position, supervisor, depots and areas open on the right.',
    'role' => 'Role :role',
    'position' => 'Position',
    'position_none' => 'None',
    'position_hint' => 'The position is for the tree only; what they see comes from the tree and the areas',
    'under' => 'Reports to',
    'nobody' => 'Nobody (top)',
    'depots' => 'Depots (several)',
    'depots_hint' => 'Without a depot they would see nothing once the wall is on',
    'areas' => 'Areas (several)',
    'areas_hint' => '⭐ New dealers that later join the area are theirs automatically',
    'extra' => 'Dealers outside the areas (one by one)',
    'extra_count' => ':count — on the bindings page',
    'extra_none' => 'None',
    'extra_add' => '+ Dealer',
    'starts_on' => 'From',
    'save' => 'Save',
    'handover' => 'Hand over — dealers to someone else',
    'saved' => 'Tree, depots and areas saved for :name.',

    'sees' => 'What they will see once the wall is on',
    'sees_own' => 'Own areas and own bound dealers',
    'sees_below' => 'Total — own and the :count below',
    'sees_depots' => 'Depots',
    'sees_depots_none' => '0 — none picked',
    'sees_targets' => 'Sales targets and achievement',
    'sees_targets_value' => 'Own and the :count below',
    'sees_all' => 'Outside the wall — sees every dealer',
    'sees_note' => 'No money collection — orders only (owner\'s earlier decision). No cost prices.',

    'not_field' => '⛔ This person is not in the field force — give them a field role or a position.',
    'bad_position' => '⛔ Position is only SR, ASM, RSM or DSM.',
    'bad_depot' => '⛔ That depot is not this company\'s.',
];
