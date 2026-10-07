<?php

declare(strict_types=1);

/*
 * Leads and opportunities — NEXUS spec §7. Its own file, so the large Sales
 * files (field, message) need not be touched alongside other work.
 */
return [
    // ── Menu and titles ────────────────────────────────────────────────
    'leads' => 'Leads',
    'leads_note' => 'New shops being worked — they stay here until they become customers',
    'opportunities' => 'Opportunities',
    'opportunities_note' => 'Possible sales — how much, how likely, by when',
    'pipeline' => 'Pipeline',
    'pipeline_note' => 'Opportunities by stage — total and probability-weighted value',
    'opportunity_stages' => 'Opportunity stages',

    // ── Leads ──────────────────────────────────────────────────────────
    'lead' => 'Lead',
    'lead_no' => 'Lead no.',
    'lead_name' => 'Shop / business name',
    'contact_person' => 'Contact person',
    'phone' => 'Phone',
    'address' => 'Address',
    'location' => 'Area',
    'owner' => 'Owner',
    'owner_me' => '— Me —',
    'status' => 'Status',
    'source_label' => 'Source',
    'lost_reason' => 'Reason lost',
    'lost_reason_hint' => 'Required when the status is "Lost"',
    'notes' => 'Notes',
    'lead_search' => 'Name, phone or lead no.',
    'new_lead' => 'New lead',
    'all_statuses' => 'All statuses',
    'no_leads' => 'No leads yet',
    'lead_saved' => 'Lead saved',

    'lead_status' => [
        'new' => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'lost' => 'Lost',
        'converted' => 'Converted',
    ],

    'source' => [
        'field_visit' => 'Field visit',
        'referral' => 'Referral',
        'phone_call' => 'Phone call',
        'walk_in' => 'Walk-in',
        'other' => 'Other',
    ],

    // ── Conversion ─────────────────────────────────────────────────────
    'convert_title' => 'Make a customer',
    'convert_note' => 'The lead\'s phone, address and contact person go to the customer. It stops if a customer already has this phone.',
    'convert' => 'Make a customer',
    'customer_name_en' => 'Customer name (English)',
    'customer_name_bn' => 'Customer name (Bangla)',
    'party_type' => 'Customer type',
    'allow_duplicate' => 'Create it even though a customer with this name exists',
    'became_customer' => 'Customer',
    'converted' => 'Customer created — :code',

    // ── Opportunities ──────────────────────────────────────────────────
    'opportunity_no' => 'Opportunity no.',
    'title' => 'Title',
    'party' => 'With',
    'customer' => 'Customer',
    'party_hint' => 'A customer or a lead — one of the two',
    'salesperson' => 'Salesperson',
    'stage' => 'Stage',
    'estimated_value' => 'Estimated value',
    'weighted_value' => 'Weighted value',
    'probability' => 'Probability (%)',
    'probability_hint' => 'Leave blank to use the stage\'s',
    'expected_close_date' => 'Expected close',
    'competitor' => 'Competitor',
    'remarks' => 'Remarks',
    'products' => 'Products',
    'products_hint' => 'At least one product; the estimated value is the sum of these rows',
    'product' => 'Product',
    'qty' => 'Quantity',
    'line_value' => 'Value',
    'opportunity_search' => 'Title or opportunity no.',
    'new_opportunity' => 'New opportunity',
    'all_stages' => 'All stages',
    'no_opportunities' => 'No opportunities yet',
    'opportunity_saved' => 'Opportunity saved',
    'create_quotation' => 'Create quotation',
    'quotation' => 'Quotation',
    'open_weighted' => 'Weighted total of open opportunities',
    'see_all' => 'See all :count',

    // ── Stage list (master) ────────────────────────────────────────────
    'stage_probability' => 'Probability (%)',
    'stage_sort_order' => 'Order',
    'stage_is_won' => 'This stage means won',
    'stage_is_won_hint' => 'A quotation can be made from a won opportunity',
    'stage_is_lost' => 'This stage means lost',

    // ── Refusals ───────────────────────────────────────────────────────
    'stage_both_won_and_lost' => 'A stage cannot be both won and lost',
    'probability_range' => 'Probability must be between 0 and 100',
    'owner_not_member' => 'This person is not a member of this company',
    'status_not_settable' => 'This status cannot be set by hand',
    'lost_needs_reason' => 'Write why it was lost',
    'already_converted' => 'This lead has already become a customer',
    'lost_cannot_convert' => 'A lost lead cannot be converted — change its status first',
    'locked_by_quotation' => 'A quotation was made — the opportunity can no longer change',
    'quotation_needs_won' => 'A quotation only from a won opportunity, and only once',
    'stage_required' => 'Pick an active stage',
    'lines_required' => 'Add at least one product',
    'party_exactly_one' => 'Pick exactly one: a customer or a lead',
    'party_not_found' => 'The chosen customer or lead was not found',
    'lead_is_lost' => 'A lost lead cannot take a new opportunity',
    'product_not_found' => 'The chosen product was not found in this company',
    'line_amount_invalid' => 'Quantity above zero and value not negative — up to four decimal places',

    // ── Number series ──────────────────────────────────────────────────
    'doc_lead' => 'Lead',
    'doc_opportunity' => 'Opportunity',
];
