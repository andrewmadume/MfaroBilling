<?php

return ['paths' => ['api/*'], 'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'], 'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'https://billing.mfarosoftware.africa,https://admin.billing.mfarosoftware.africa'))), 'allowed_headers' => ['Content-Type', 'X-CSRF-TOKEN', 'X-Organisation-ID', 'Idempotency-Key', 'Authorization', 'Accept'], 'exposed_headers' => ['Content-Disposition'], 'max_age' => 600, 'supports_credentials' => true];
