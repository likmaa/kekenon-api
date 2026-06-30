<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/cgu', fn() => view('legal.cgu'));
Route::get('/confidentialite', fn() => view('legal.privacy'));
Route::get('/privacy', fn() => redirect('/confidentialite'));
