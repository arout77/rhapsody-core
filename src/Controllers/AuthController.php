<?php
namespace Rhapsody\Core\Controllers;

use Doctrine\ORM\EntityManager;
use Rhapsody\Core\Auth\AuthenticatableInterface;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Entities\User;
use Rhapsody\Core\Events\EventDispatcher;
use Rhapsody\Core\Events\UserRegistered;
use Rhapsody\Core\Mailer;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Session;
use Rhapsody\Core\Validator;
use Twig\Environment;

class AuthController extends BaseController implements AuthenticatableInterface
{
    /**
     * How long a password reset token remains valid, in minutes.
     */
    private const RESET_TOKEN_TTL_MINUTES = 60;

    /**
     * @param EntityManager   $em
     * @param Validator       $validator
     * @param EventDispatcher $dispatcher
     * @param Mailer          $mailer
     * @param Environment     $twig
     */
    public function __construct(
        protected EntityManager $em,
        protected Validator $validator,
        protected EventDispatcher $dispatcher,
        protected Mailer $mailer,
        Environment $twig
    ) {
        parent::__construct($twig);
    }

    /**
     * Display the login form.
     * @return Response
     */
    public function showLoginForm(): Response
    {
        // The "@core" namespace maps directly to vendor/arout/rhapsody-core/resources/views/
        return $this->view('auth/login.twig');
    }

    /**
     * Handle an incoming authentication request.
     * @param  Request    $request
     * @return Response
     */
    public function login(Request $request): Response
    {
        $data = $request->getBody();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if ($user && password_verify($data['password'], $user->getPassword())) {
            Session::regenerate();
            Session::set('user_id', $user->getUserId());
            Session::set('user_name', $user->getName());
            Session::set('user_email', $user->getEmail());
            return redirect('/dashboard');
        }

        return redirect('/login')->with('error', 'Invalid email or password.');
    }

    /**
     * Display the registration form.
     * @return Response
     */
    public function showRegisterForm(): Response
    {
        return $this->view('auth/register.twig');
    }

    /**
     * Handle an incoming registration request.
     * @param  Request    $request
     * @return Response
     */
    public function register(Request $request): Response
    {
        $data  = $request->getBody();
        $rules = [
            'name'     => 'required|min:2',
            'email'    => 'required|email|unique:User',
            'password' => 'required|min:8|confirmed',
        ];

        if ($this->validator->validate($data, $rules)) {
            // Create a new User entity
            $user = new User();
            $user->setName($data['name']);
            $user->setEmail($data['email']);
            // Password is encrypted in entity
            $user->setPassword($data['password']);

            try {
                // Tell Doctrine to save the user
                $this->em->persist($user);
                $this->em->flush();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                // Backstop for a race condition: another request registered this
                // email between our unique check above and this flush().
                error_log("Registration race condition caught: " . $e->getMessage());
                return $this->view('auth/register.twig', [
                    'errors' => ['email' => ['An account with this email already exists.']],
                    'old'    => $data,
                ]);
            }

            // Dispatch the registration event
            $this->dispatcher->dispatch(new UserRegistered($user));

            // Automatically log the new user in
            Session::regenerate();
            Session::set('user_id', $user->getUserId());
            return redirect('/dashboard');
        }

        return $this->view('auth/register.twig', [
            'errors' => $this->validator->getErrors(),
            'old'    => $data,
        ]);
    }

    /**
     * Log the user out of the application.
     * @return Response
     */
    public function logout(): Response
    {
        Session::destroy();
        return redirect('/login');
    }

    /**
     * Display the "forgot password" request form.
     * @return Response
     */
    public function showForgotPasswordForm(): Response
    {
        return $this->view('auth/forgot-password.twig');
    }

    /**
     * Handle a request to send a password reset link.
     * @param  Request    $request
     * @return Response
     */
    public function sendResetLink(Request $request): Response
    {
        $data  = $request->getBody();
        $rules = ['email' => 'required|email'];

        if (! $this->validator->validate($data, $rules)) {
            return $this->view('auth/forgot-password.twig', [
                'errors' => $this->validator->getErrors(),
                'old'    => $data,
            ]);
        }

        $email = $data['email'];
        $user  = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        // Only actually send/store a token if the account exists, but always
        // show the same generic confirmation message either way. This avoids
        // leaking which email addresses are registered (user enumeration).
        if ($user) {
            $conn = $this->em->getConnection();

            // Invalidate any previous outstanding tokens for this email.
            $conn->executeStatement(
                'DELETE FROM password_resets WHERE email = ?',
                [$email]
            );

            $rawToken    = bin2hex(random_bytes(32));
            $hashedToken = hash('sha256', $rawToken);

            $conn->executeStatement(
                'INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())',
                [$email, $hashedToken]
            );

            $resetUrl = rtrim($_ENV['APP_URL'] ?? '', '/')
                . ($_ENV['APP_BASE_URL'] ?? '')
                . '/reset-password/' . $rawToken;

            try {
                $this->mailer->send(
                    $email,
                    'Reset your password',
                    '<p>We received a request to reset your password. Click the link below to choose a new one:</p>'
                    . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>'
                    . '<p>This link will expire in ' . self::RESET_TOKEN_TTL_MINUTES . ' minutes. '
                    . 'If you did not request a password reset, you can safely ignore this email.</p>'
                );
            } catch (\Exception $e) {
                error_log("Password reset email failed to send: " . $e->getMessage());
                // Don't reveal the failure to the client; fall through to the
                // generic confirmation message below.
            }
        }

        return redirect('/forgot-password')->with(
            'success',
            'If an account exists for that email, a password reset link has been sent.'
        );
    }

    /**
     * Display the form to choose a new password, given a valid token.
     * @param  string     $token
     * @return Response
     */
    public function showResetForm(string $token): Response
    {
        return $this->view('auth/reset-password.twig', ['token' => $token]);
    }

    /**
     * Handle the submission of a new password.
     * @param  Request    $request
     * @return Response
     */
    public function resetPassword(Request $request): Response
    {
        $data  = $request->getBody();
        $rules = [
            'token'    => 'required',
            'password' => 'required|min:8|confirmed',
        ];

        if (! $this->validator->validate($data, $rules)) {
            return $this->view('auth/reset-password.twig', [
                'errors' => $this->validator->getErrors(),
                'token'  => $data['token'] ?? '',
            ]);
        }

        $hashedToken = hash('sha256', $data['token']);
        $conn        = $this->em->getConnection();

        $resetRow = $conn->fetchAssociative(
            'SELECT * FROM password_resets WHERE token = ? AND created_at >= (NOW() - INTERVAL ? MINUTE)',
            [$hashedToken, self::RESET_TOKEN_TTL_MINUTES]
        );

        if (! $resetRow) {
            return $this->view('auth/reset-password.twig', [
                'errors' => ['token' => ['This password reset link is invalid or has expired. Please request a new one.']],
                'token'  => $data['token'],
            ]);
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $resetRow['email']]);

        if (! $user) {
            // The account was deleted after the reset link was issued.
            $conn->executeStatement('DELETE FROM password_resets WHERE token = ?', [$hashedToken]);
            return redirect('/forgot-password')->with('error', 'We could not find an account for this reset link.');
        }

        $user->setPassword($data['password']);
        $this->em->flush();

        // Invalidate the token (and any others for this email) now that it's used.
        $conn->executeStatement('DELETE FROM password_resets WHERE email = ?', [$resetRow['email']]);

        return redirect('/login')->with('success', 'Your password has been reset. Please log in.');
    }
}
