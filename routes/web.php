<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\GroupCsvController;
use App\Http\Controllers\MediaFileController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Groups;
use App\Livewire\Media;
use App\Livewire\SendMessage;
use App\Livewire\Templates;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/', Dashboard\Index::class)->name('dashboard');

    Route::get('/groups/export', [GroupCsvController::class, 'export'])->name('groups.export');
    Route::get('/groups/import/sample', [GroupCsvController::class, 'sample'])->name('groups.import.sample');
    Route::livewire('/send', SendMessage\Compose::class)->name('send.create');

    Route::livewire('/groups', Groups\Index::class)->name('groups.index');
    Route::livewire('/groups/{group}', Groups\Show::class)->name('groups.show');

    Route::livewire('/templates', Templates\Index::class)->name('templates.index');

    Route::livewire('/media', Media\Index::class)->name('media.index');
    Route::get('/media/{media}/file', [MediaFileController::class, 'show'])->name('media.file');
    Route::get('/media/{media}/thumbnail', [MediaFileController::class, 'thumbnail'])->name('media.thumbnail');
    Route::get('/media/{media}/download', [MediaFileController::class, 'download'])->name('media.download');

    // Screens built in later phases show a placeholder until then.
    foreach ([
        'history.index' => '/history',
        'failed.index' => '/failed',
        'settings.index' => '/settings',
    ] as $name => $uri) {
        Route::view($uri, 'pages.coming-soon', ['item' => Navigation::find($name)])->name($name);
    }

    Route::post('/logout', LogoutController::class)->name('logout');
});
