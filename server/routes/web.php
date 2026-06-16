<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Session login removed — auth is JWT, issued by the vanilla service (/api/auth/login).
