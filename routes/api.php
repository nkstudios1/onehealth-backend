<?php

use App\Http\Responses\ApiResponder;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes   (every URL here starts with /api)
|--------------------------------------------------------------------------
|
| HOW TO ADD A MODULE: create routes/api/v1/<module>.php. It is loaded automatically
| under /api/v1, inside the "api" middleware group (JSON only, cookie session, CSRF
| protection). Nothing to register here. See docs/BACKEND-GUIDE.md.
|
*/

// Is the API up? Used by the frontend and by uptime checks. Touches nothing sensitive.
Route::get('ping', fn () => ApiResponder::success(['status' => 'ok'], 'pong'));

Route::prefix('v1')->name('v1.')->group(function () {
    $files = glob(__DIR__.'/api/v1/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        require $file;
    }
});
