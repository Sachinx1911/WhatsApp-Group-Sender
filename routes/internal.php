<?php

use App\Http\Controllers\Internal\WhatsAppStateController;
use Illuminate\Support\Facades\Route;

/*
| Called only by the local WhatsApp Web worker (127.0.0.1 + X-Worker-Token).
| Loaded from bootstrap/app.php under /internal.
*/

Route::post('/whatsapp/state', WhatsAppStateController::class)->name('internal.whatsapp.state');
