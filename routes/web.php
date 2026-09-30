<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\GroupCsvController;
use App\Http\Controllers\MediaFileController;
use App\Livewire\Auth\Login;
use App\Livewire\Campaigns;
use App\Livewire\Dashboard;
use App\Livewire\FailedMessages;
use App\Livewire\Groups;
use App\Livewire\History;
use App\Livewire\Media;
use App\Livewire\SendMessage;
use App\Livewire\Settings;
use App\Livewire\Templates;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/', Dashboard\Index::class)->name('dashboard');

    Route::get('/groups/export', [GroupCsvController::class, 'export'])->name('groups.export');
    Route::get('/groups/import/sample', [GroupCsvController::class, 'sample'])->name('groups.import.sample');
    Route::livewire('/send', SendMessage\Compose::class)->name('send.create');
    Route::livewire('/history', History\Index::class)->name('history.index');
    Route::livewire('/failed', FailedMessages\Index::class)->name('failed.index');
    Route::livewire('/campaigns/{campaign}', Campaigns\Show::class)->name('campaigns.show');

    Route::livewire('/groups', Groups\Index::class)->name('groups.index');
    Route::livewire('/groups/{group}', Groups\Show::class)->name('groups.show');

    Route::livewire('/templates', Templates\Index::class)->name('templates.index');

    Route::livewire('/media', Media\Index::class)->name('media.index');
    Route::get('/media/{media}/file', [MediaFileController::class, 'show'])->name('media.file');
    Route::get('/media/{media}/thumbnail', [MediaFileController::class, 'thumbnail'])->name('media.thumbnail');
    Route::get('/media/{media}/download', [MediaFileController::class, 'download'])->name('media.download');

    Route::livewire('/settings', Settings\Index::class)->name('settings.index');
    Route::get('/settings/backups/{name}', [BackupController::class, 'download'])->name('backups.download');

    Route::post('/logout', LogoutController::class)->name('logout');
});
