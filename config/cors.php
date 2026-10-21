<?php

/*
| Which websites may call this API from a browser. The patient app runs on a different
| origin than the API (localhost:3001 vs localhost:8000), so without this the browser
| blocks every request. Set CORS_ALLOWED_ORIGINS in .env (comma separated, no trailing slash).
| NEVER use "*" here: the app sends cookies, and browsers refuse "*" with cookies (and it
| would be unsafe if they didn't).
*/
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3001'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-Id'],
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],
    'max_age' => 600,
    // Required for the session cookie to be sent. The frontend uses credentials: "include".
    'supports_credentials' => true,
];
