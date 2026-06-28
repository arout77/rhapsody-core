<?php
namespace Rhapsody\Core\Contracts;

use Rhapsody\Core\Request;
use Rhapsody\Core\Response;

interface AuthenticatableInterface
{
    public function showLoginForm(): Response;
    public function showRegisterForm(): Response;
    public function login(Request $request): Response;
    public function register(Request $request): Response;
    public function logout(): Response;
    public function getAuthIdentifier();
    public function getAuthPassword();
}
