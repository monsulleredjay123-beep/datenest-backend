<?php

namespace App\Policies;

use App\Models\Establishment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EstablishmentPolicy
{
    /** Ang owner ra sa establishment ang pwede mo-edit */
    public function update(User $user, Establishment $establishment): Response
    {
        return $user->id === $establishment->owner_id
            ? Response::allow()
            : Response::deny('You can only edit your own listing.');
    }
}