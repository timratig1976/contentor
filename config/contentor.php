<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ICP-Labels (Ideal Customer Profiles)
    |--------------------------------------------------------------------------
    |
    | Zentrale, menschenlesbare Bezeichnungen für die ICP-Keys, die in den
    | Angles verwendet werden. Benutzerdefinierte ICPs (z. B. B2B-4, B2B-5)
    | werden über die `icp_definitions` der jeweiligen Strategie aufgelöst
    | und haben Vorrang vor diesen Standard-Labels.
    |
    */
    'icp_labels' => [
        'B2B-1' => 'CRM-Entscheider Mittelstand',
        'B2B-2' => 'Head of Sales / RevOps',
        'B2B-3' => 'CRM- & Prozessverantwortliche',
        'B2C'   => 'Endkunden (B2C)',
        'UNI'   => 'Hochschulen & Forschung',
        'BK'    => 'Bestandskunden',
    ],

];