<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('projects')->name('projects.')->group(function () {
    Route::livewire('/', 'pages::projects.index')->name('index');

    Route::livewire('create', 'pages::projects.form')
        ->middleware('can:create,App\Models\Project')
        ->name('create');

    Route::livewire('{project}', 'pages::projects.show')
        ->middleware('can:view,project')
        ->name('show');

    Route::livewire('{project}/edit', 'pages::projects.form')
        ->middleware('can:update,project')
        ->name('edit');
});
