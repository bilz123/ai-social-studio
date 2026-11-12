<?php

use App\Http\Middleware\AddApiVersionHeader;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API versioning
|--------------------------------------------------------------------------
| The "api" prefix is applied by bootstrap/app.php. Each major version lives in its own
| routes/api/vN.php file and URL prefix. Add routes/api/v2.php beside v1 when a breaking
| change is needed; never change the meaning of an existing v1 route.
*/

Route::prefix('v1')
    ->name('v1.')
    ->middleware(AddApiVersionHeader::class.':v1')
    ->group(base_path('routes/api/v1.php'));
