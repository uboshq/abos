<?php

declare(strict_types=1);

/*
 * What this module's approvable actions are called in the flow builder.
 *
 * Only the module knows the name — ApprovalFlowService::labels() turns
 * "finance · withdrawal" into human words from here.
 */

return [
    'withdrawal' => 'Owner withdrawal',
    'profit' => 'Profit declaration',

    // Finance's other money actions — audit 4 Oct 2026 ([[FinanceSignature]])
    'hand_loan' => 'Hand loan given or repaid',
    'deposit' => 'Deposit — opening, instalment, profit, encashment',
    'rental' => 'Rent deposit, monthly rent and refund',
    'bank_facility' => 'Running loan brought in',
    'capitalise' => 'Year-end profit moved to capital',
    // Re-audit, 9 Oct 2026 ([[InsuranceClaimService]])
    'insurance_claim' => 'Insurance claim — approval, money received, closing',
];
