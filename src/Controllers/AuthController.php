<?php
namespace Rhapsody\Core\Controllers;

use App\Entities\User;
use App\Events\UserRegistered;
use Doctrine\ORM\EntityManager;
use Rhapsody\Core\Auth\AuthenticatableInterface;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Events\EventDispatcher;
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
    public function showLoginForm()
    {
        // The "@core" namespace maps directly to vendor/arout/rhapsody-core/resources/views/
        return $this->twig->render('@core/auth/login.twig');
    }

    /**
     * Handle an incoming authentication request.
     * * @param Request $request
     * @return Response
     */
    public function login(Request $request): Response
    {
        $data = $request->getBody();

        // Find the user by email using the EntityManager
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if ($user && password_verify($data['password'], $user->getPassword())) {
            Session::regenerate();
            Session::set('user_id', $user->getUserId());
            return redirect('/dashboard');
        }

        return $this->view('@core/auth/login.twig', [
            'errors' => ['login' => 'Invalid email or password.'],
            'old'    => ['email' => $data['email'] ?? ''],
        ]);
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
            $user->setPassword(password_hash($data['password'], PASSWORD_BCRYPT));

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
