<?php

use App\Http\Controllers\Api\SkinArticleController;
use Illuminate\Support\Facades\Route;

Route::get('/skin-article', SkinArticleController::class)->name('api.skin-article');
