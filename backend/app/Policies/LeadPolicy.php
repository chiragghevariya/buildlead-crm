<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Admin + manager: every lead. Sales: only leads assigned to them.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // list is scoped with Lead::visibleTo()
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->canSeeAllLeads() || $lead->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead);
    }

    public function reassign(User $user, Lead $lead): bool
    {
        return $user->canSeeAllLeads();
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->canSeeAllLeads();
    }
}
