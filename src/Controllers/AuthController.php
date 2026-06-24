<?php
namespace Rhapsody\Core\Controllers;

use Doctrine\ORM\EntityManager;
use Rhapsody\Core\Auth\AuthenticatableInterface;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Entities\User;
use Rhapsody\Core\Events\EventDispatcher;
use Rhapsody\Core\Events\UserRegistered;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Session;
use Rhapsody\Core\Validator;
use Twig\Environment;

class AuthController extends BaseController implements AuthenticatableInterface
{
    /**
     * @param EntityManager $em
     * @param Validator $validator
     * @param EventDispatcher $dispatcher
     * @param Environment $twig
     */
    public function __construct(
        protected EntityManager $em,
        protected Validator $validator,
        protected EventDispatcher $dispatcher,
        Environment $twig
    ) {
        parent::__construct($twig);
    }

    /**
     * Display the login form.
     * * @return Response
     */
    public function showLoginForm(): Response
    {
        // The "@core" namespace maps directly to vendor/arout/rhapsody-core/resources/views/
        return $this->view('@core/auth/login.twig');
    }

    /**
     * Handle an incoming authentication request.
     * * @param Request $request
     * @return Response
     */
    public function login(Request $request): Response
    {
        $data = $request->getBody();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        // DEBUG: Log the user and password verification
        error_log("User found: " . ($user ? 'yes' : 'no'));
        if ($user) {
            error_log("Password verify result: " . (password_verify($data['password'], $user->getPassword()) ? 'true' : 'false'));
            error_log("Input password: " . $data['password']);
            error_log("Stored hash: " . $user->getPassword());
        }

        if ($user && password_verify($data['password'], $user->getPassword())) {
            error_log("Login successful, redirecting to /dashboard");
            Session::regenerate();
            Session::set('user_id', $user->getUserId());
            Session::set('user_name', $user->getName());
            Session::set('user_email', $user->getEmail());
            return redirect('/dashboard');
        }

        error_log("Login failed, redirecting to /login with error");
        return redirect('/login')->with('error', 'Invalid email or password.');
    }

    /**
     * Display the registration form.
     * * @return Response
     */
    public function showRegisterForm(): Response
    {
        return $this->view('@core/auth/register.twig');
    }

    /**
     * Handle an incoming registration request.
     * * @param Request $request
     * @return Response
     */
    public function register(Request $request): Response
    {
        $data  = $request->getBody();
        $rules = [
            'name'     => 'required|min:2',
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
        ];

        if ($this->validator->validate($data, $rules)) {
            // Create a new User entity
            $user = new User();
            $user->setName($data['name']);
            $user->setEmail($data['email']);
            // Password is encrypted in entity
            $user->setPassword($data['password']);

            // Tell Doctrine to save the user
            $this->em->persist($user);
            $this->em->flush();

            // Dispatch the registration event
            $this->dispatcher->dispatch(new UserRegistered($user));

            // Automatically log the new user in
            Session::regenerate();
            Session::set('user_id', $user->getUserId());
            return redirect('/dashboard');
        }

        return $this->view('@core/auth/register.twig', [
            'errors' => $this->validator->getErrors(),
            'old'    => $data,
        ]);
    }

    /**
     * Log the user out of the application.
     * * @return Response
     */
    public function logout(): Response
    {
        Session::destroy();
        return redirect('/login');
    }
}
