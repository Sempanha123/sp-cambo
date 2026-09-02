<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return redirect()->away(config('app.frontend_url', 'http://localhost:3000').'/login');
})->name('login');
