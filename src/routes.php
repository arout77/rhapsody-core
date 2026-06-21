<?php

use Rhapsody\Core\Controllers\AuthController;
use Rhapsody\Core\Controllers\DocsController;
use Rhapsody\Core\Routing\Router;

// Assumes your Router instance is injected or accessible
Router::get('/login', [AuthController::class, 'showLoginForm']);
Router::get('/docs', [DocsController::class, 'index']);
Router::get('/docs/authentication', [DocsController::class, 'authentication']);
Router::get('/docs/caching', [DocsController::class, 'performance']);
Router::get('/docs/cli', [DocsController::class, 'cli']);
Router::get('/docs/configuration', [DocsController::class, 'configuration']);
Router::get('/docs/controllers', [DocsController::class, 'controllers']);
Router::get('/docs/debugging', [DocsController::class, 'debugging']);
Router::get('/docs/doctrine', [DocsController::class, 'doctrine']);
Router::get('/docs/error-handling', [DocsController::class, 'errorHandling']);
Router::get('/docs/events', [DocsController::class, 'events']);
Router::get('/docs/file-uploader', [DocsController::class, 'fileUploader']);
Router::get('/docs/image-processing', [DocsController::class, 'imageProcessing']);
Router::get('/docs/installation', [DocsController::class, 'installation']);
Router::get('/docs/logging', [DocsController::class, 'logging']);
Router::get('/docs/mailer', [DocsController::class, 'mailer']);
Router::get('/docs/middleware', [DocsController::class, 'middleware']);
Router::get('/docs/models', [DocsController::class, 'models']);
Router::get('/docs/pagination', [DocsController::class, 'pagination']);
Router::get('/docs/request', [DocsController::class, 'request']);
Router::get('/docs/response', [DocsController::class, 'response']);
Router::get('/docs/routing', [DocsController::class, 'routing']);
Router::get('/docs/security', [DocsController::class, 'security']);
Router::get('/docs/seo', [DocsController::class, 'seo']);
Router::get('/docs/themes', [DocsController::class, 'themes']);
Router::get('/docs/updating', [DocsController::class, 'updating']);
Router::get('/docs/validation', [DocsController::class, 'validation']);
Router::get('/docs/views', [DocsController::class, 'views']);
Router::get('/docs/recaptcha', [DocsController::class, 'recaptcha']);
Router::get('/docs/testing', [DocsController::class, 'testing']);
Router::get('/docs/ddos-protection', [DocsController::class, 'ddosProtection']);
Router::get('/docs/console-commands', [DocsController::class, 'consoleCommands']);
