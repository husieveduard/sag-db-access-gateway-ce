<?php

return [
    // Independent from APP_KEY. Supply base64:<32 random bytes> only to services
    // that must access target database credentials; never commit the key.
    'encryption_key' => env('SAG_CE_CREDENTIAL_KEY'),
];
