<?php

use Rhapsody\Core\Controllers\AuthController;
use Rhapsody\Core\Controllers\DocsController;

// Assumes your Router instance is injected or accessible
$router->get('/login', [AuthController::class, 'showLoginForm']);
$router->get('/rhapsody/docs', [DocsController::class, 'index']);
