<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\StudentAssistanceRequest;
use App\Models\Transaction;
use App\Models\User;

/** Retired simulation seam. Never creates synthetic settlement evidence. */
final class PaymentService
{
    public function disburse(StudentAssistanceRequest $request, User $student, Organization $organization, float $amount): ?Transaction
    {
        throw new \LogicException('Legacy simulated assistance payouts are disabled. Record an authorized payment workflow and verify settlement before reporting funds received.');
    }
}
