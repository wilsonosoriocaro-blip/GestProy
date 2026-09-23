<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The executive dashboard of the projects module is the home of the app.
    Route::livewire('dashboard', 'pages::projects.dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/projects.php';
