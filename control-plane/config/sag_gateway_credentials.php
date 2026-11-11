<?php

return [
    // Keep disabled until PostgreSQL/MySQL/MSSQL brokers actually enforce
    // temporary username/password on the wire.
    'issuance_enabled' => env('SAG_CE_TEMP_CREDENTIAL_ISSUANCE_ENABLED', false),
];
