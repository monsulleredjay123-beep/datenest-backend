<?php

return [
    // Pila ka lahi nga admin ang kinahanglan mo-approve sa bag-ong establishment registration.
    // Default 1 (usa ra ka admin). Kung mag-dugang ug admin, pwede i-set sa .env: DATENEST_REQUIRED_APPROVALS=2
    'required_approvals' => (int) env('DATENEST_REQUIRED_APPROVALS', 1),

    // Ang na-reject nga registration ma-delete awtomatik human sa ingon ka adlaw.
    // (Sa sulod niining panahona, pwede pa i-ayo ug i-resubmit sa owner.)
    'rejected_retention_days' => (int) env('DATENEST_REJECTED_RETENTION_DAYS', 2),

    // ---------- ADS (advertising contract) ----------
    // LIBRE na ang ads: walay bayad. Ang admin gihapon ang mo-approve, ug naa gihapon ang contract (term).

    // Contract (pag-ads sa establishment): min/max nga bulan.
    'contract_min_months' => 1,
    'contract_max_months' => 60,

    // I-remind ang owner kung ilang adlaw na lang mo-expire ang ads.
    'ads_reminder_days' => 7,

    // Timezone sa reservations (oras/petsa). Gamiton gihapon bisan UTC ang app timezone.
    'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),

    // Reservation: minimum nga minuto gikan karon (kung karon nga adlaw).
    'reservation_lead_minutes' => 30,

    // Reservation: pwede ra i-pili ang oras matag N minutos; ang kataposang slot N minutos sa dili pa mo-close;
    // ug hangtod pila ka adlaw sa unahan pwede mag-reserve (20 ka adlaw ra). (Parehas sa core/slots.ts sa Angular.)
    'reservation_slot_minutes' => 30,
    'reservation_last_slot_before_close' => 60,
    'reservation_max_days_ahead' => 20,
];