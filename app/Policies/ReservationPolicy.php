<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReservationPolicy
{
    /** Owner sa establishment: approve / reject / complete */
    public function manage(User $user, Reservation $reservation): Response
    {
        return $reservation->establishment->owner_id === $user->id
            ? Response::allow()
            : Response::deny('You can only manage reservations of your own establishment.');
    }

    /** Customer nga naghimo sa reservation: pay / review */
    public function own(User $user, Reservation $reservation): Response
    {
        return $reservation->customer_id === $user->id
            ? Response::allow()
            : Response::deny('This is not your reservation.');
    }
}