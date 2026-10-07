<?php

declare(strict_types=1);

return [
    'gift_needs_quantity' => 'The gift quantity must be more than zero.',

    /* The product is named — otherwise you would have to hunt for which gift stalled */
    'gift_needs_lot' => ':product is tracked by lot, so the gift has to say which lot '
        .'it leaves from. Without one the floor would drop while the lot totals stayed '
        .'whole, and the next sale would allocate from an empty lot.',

    'type_not_built' => ':type offers cannot run yet — the engine does not know them. '
        .'An offer of this type would say "running" and give the buyer nothing.',
    'cannot_approve_own' => 'You created this offer, so someone else has to approve it. '
        .'Approval means a second person looked.',
    'cannot_move' => 'An offer that is ":from" cannot become ":to".',
    'cannot_reschedule' => 'The dates of an offer that is ":status" cannot change. Copy it into a new offer instead.',
    'not_eligible' => ':code no longer fits this line — it may have been paused, changed or '
        .'ended after the screen was drawn. Reopen the screen.',
    'budget_exceeded' => 'The budget of :code is spent — the ceiling is :ceiling and :used has gone. '
        .'This line would go over it. The budget can be raised from the offer page.',
    'budget_near' => ':percent% of the budget of :code has been spent.',
    'gift_needs_product' => 'A gift step needs a product from this company. Without one the step '
        .'would be quietly skipped and the buyer would never get the gift.',
    'range_upside_down' => '"To" is below "From" — such a step would match no number at all.',
    'rules_frozen' => 'The rules of an offer that is ":status" cannot change — what the approver saw is '
        .'what runs. Pause it and make a new offer instead.',
    'override_needs_reason' => 'An override needs a reason — money is leaving outside the rules, '
        .'and without "why" nobody can say later whether it was fair.',
    'override_not_negative' => 'The benefit cannot go below zero.',
    'product_not_yours' => 'That product does not belong to this company.',
    'ends_before_it_starts' => 'The offer ends before it starts, so it would never run at all.',

    'budget_closed' => 'The budget of a ":status" offer cannot change — it will not apply to any bill again.',
    'budget_not_positive' => 'The budget must be above zero. To stop the offer, pause or cancel it.',
    'budget_below_used' => ':used is already spent — the ceiling cannot go below it. '
        .'The page would show over 100% and that money cannot be taken back. To allow nothing more, set :used.',

    'gift_over_owed' => ':owed owed, :issued already given — no more than :left can go. '
        .'Giving more would send stock out as a gift no bill carries.',
    'gift_for_cancelled_bill' => 'The bill was cancelled — its gift can no longer be issued.',

    'already_applied' => ':code is already on this line — applying it twice would double the discount.',
    'override_on_reversed' => 'The bill was cancelled — its benefit can no longer be changed.',
    'gift_shelf_short' => 'Only :available :product can go from :warehouse — the rest is promised to orders, held, or not there. Give less, or issue from another warehouse.',
    'gift_serial_whole' => ':product is tracked piece by piece — the gift must be a whole number.',
    'gift_needs_serials' => ':product is tracked by serial — :qty piece(s) need :qty serial number(s); :given given.',
    'gift_serial_not_here' => 'Serial :no is not a :product sitting in :warehouse (unknown, another product, another store, or already out).',
    'benefit_not_for_type' => 'A ":type" offer cannot carry ":benefit" — the name would promise one thing and the bill give another.',

    'gift_lot_is_another_products' => 'That lot belongs to a different product. If the '
        .'gift product and the lot product differ, both stock figures go wrong and '
        .'nothing breaks to say so.',

    // Offers on sales papers — 29 September 2026
    'not_found' => 'The offer was not found — it may have been deleted or belong to another company.',
    'not_a_bill_discount' => ':code is not a money discount (goods, points or credit) — it does not sit on a bill line. Give goods offers from the gift screen.',
    'not_applied_here' => 'The offer is not applied on this line — it may already have been taken off.',
];
