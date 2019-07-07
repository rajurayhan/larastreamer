<?php

Route::get('/stream/{filename}', 'Raju\Streamer\Controllers\StreamController@stream')->name('stream');
Route::get('/streamer', 'Raju\Streamer\Controllers\StreamController@streamer')->name('streamer');
