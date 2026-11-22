<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\SzamlazzHu\Http\InvoicingController as CP;
use Sellissimo\SzamlazzHu\Http\PrivatePage;

Route::prefix('sellissimo/szamlazz')->name('szamlazz.')->middleware(PrivatePage::class)->group(function () {
    Route::get('settings', [CP::class, 'settings'])->name('settings');
    Route::post('settings', [CP::class, 'save'])->name('save');
    Route::get('queue', [CP::class, 'queue'])->name('queue');
    Route::get('orders/{order}', [CP::class, 'order'])->whereUuid('order')->name('order');
    Route::post('orders/{order}/preview', [CP::class, 'preview'])->whereUuid('order')->name('preview');
    Route::post('orders/{order}/issue', [CP::class, 'issue'])->whereUuid('order')->name('issue');
    Route::post('orders/{order}/match', [CP::class, 'match'])->whereUuid('order')->name('match');
    Route::post('orders/{order}/review', [CP::class, 'review'])->whereUuid('order')->name('review');
    Route::post('documents/{document}/lookup', [CP::class, 'lookup'])->whereUuid('document')->name('lookup');
    Route::get('documents/{document}/pdf', [CP::class, 'pdf'])->whereUuid('document')->name('pdf');
});
