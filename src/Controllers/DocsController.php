<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\BaseController;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Twig\Environment;

/**
 * Handles rendering of the framework documentation pages.
 */
class DocsController extends BaseController
{
    /**
     * @param Environment $twig
     */
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    /**
     * Shows the main documentation index page.
     */
    public function index(Request $request): Response
    {
        return $this->view('@core/docs/index.twig', [], [
            'title'         => 'Documentation – Rhapsody PHP Framework',
            'description'   => 'Complete documentation for the Rhapsody PHP framework – installation, routing, controllers, views, database, and more.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the installation and setup guide.
     */
    public function installation(Request $request): Response
    {
        return $this->view('@core/docs/installation.twig', [], [
            'title'         => 'Installation & Setup – Rhapsody Documentation',
            'description'   => 'Learn how to install Rhapsody via Composer, configure your environment, set up the database, and run the framework.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the routing documentation.
     */
    public function routing(Request $request): Response
    {
        return $this->view('@core/docs/routing.twig', [], [
            'title'         => 'Routing – Rhapsody Documentation',
            'description'   => 'Understand how to define routes, handle dynamic parameters, and use middleware in the Rhapsody router.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the request object documentation.
     */
    public function request(Request $request): Response
    {
        return $this->view('@core/docs/request.twig', [], [
            'title'         => 'The Request Object – Rhapsody Documentation',
            'description'   => 'Learn how to use the Request object to access GET, POST, JSON, files, and generate canonical URLs.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the response object documentation.
     */
    public function response(Request $request): Response
    {
        return $this->view('@core/docs/response.twig', [], [
            'title'         => 'The Response Object – Rhapsody Documentation',
            'description'   => 'Learn how to create and customize HTTP responses, including JSON responses and view rendering.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the controllers documentation.
     */
    public function controllers(Request $request): Response
    {
        return $this->view('@core/docs/controllers.twig', [], [
            'title'         => 'Controllers – Rhapsody Documentation',
            'description'   => 'Learn how to create controllers, handle requests, return responses, and inject dependencies.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the models and database documentation.
     */
    public function models(Request $request): Response
    {
        return $this->view('@core/docs/models.twig', [], [
            'title'         => 'Models & Database (PDO) – Rhapsody Documentation',
            'description'   => 'Learn how to interact with your database using the PDO wrapper, run queries, and manage models.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the Doctrine ORM documentation.
     */
    public function doctrine(Request $request): Response
    {
        return $this->view('@core/docs/doctrine.twig', [], [
            'title'         => 'Doctrine ORM – Rhapsody Documentation',
            'description'   => 'Integrate Doctrine ORM with Rhapsody for advanced database mapping and entity management.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the views and templating documentation.
     */
    public function views(Request $request): Response
    {
        return $this->view('@core/docs/views.twig', [], [
            'title'         => 'Views & Templating (Twig) – Rhapsody Documentation',
            'description'   => 'Learn how to use Twig templates, extend layouts, pass data, and create reusable components.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the validation documentation.
     */
    public function validation(Request $request): Response
    {
        return $this->view('@core/docs/validation.twig', [], [
            'title'         => 'Validation – Rhapsody Documentation',
            'description'   => 'Learn how to validate incoming data using the powerful validation engine with custom rules.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the authentication and middleware documentation.
     */
    public function middleware(Request $request): Response
    {
        return $this->view('@core/docs/middleware.twig', [], [
            'title'         => 'Middleware & Authentication – Rhapsody Documentation',
            'description'   => 'Learn how to protect routes with middleware, implement authentication, and manage user sessions.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the CLI documentation.
     */
    public function cli(Request $request): Response
    {
        return $this->view('@core/docs/cli.twig', [], [
            'title'         => 'Rhapsody Console (CLI) – Rhapsody Documentation',
            'description'   => 'Learn about the built-in CLI commands for generating code, clearing caches, and running tasks.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the mailer documentation.
     */
    public function mailer(Request $request): Response
    {
        return $this->view('@core/docs/mailer.twig', [], [
            'title'         => 'Sending Mail – Rhapsody Documentation',
            'description'   => 'Learn how to send emails using the Mailer component with SMTP and templated messages.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the SEO helpers documentation.
     */
    public function seo(Request $request): Response
    {
        return $this->view('@core/docs/seo.twig', [], [
            'title'         => 'SEO Helpers (Schema.org & Sluggify) – Rhapsody Documentation',
            'description'   => 'Learn how to use SchemaOrg structured data and generate SEO-friendly slugs with Sluggify.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the theme customization documentation.
     */
    public function themes(Request $request): Response
    {
        return $this->view('@core/docs/themes.twig', [], [
            'title'         => 'Theme Customization & Architecture – Rhapsody Documentation',
            'description'   => 'Learn how to extend layouts, customize styles, and build decoupled theme front-ends using Twig inheritance.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the pagination documentation.
     */
    public function pagination(Request $request): Response
    {
        return $this->view('@core/docs/pagination.twig', [], [
            'title'         => 'Pagination – Rhapsody Documentation',
            'description'   => 'Learn how to implement pagination in your listings using the Pagination helper.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the file uploader documentation.
     */
    public function fileUploader(Request $request): Response
    {
        return $this->view('@core/docs/file-uploader.twig', [], [
            'title'         => 'File Uploader – Rhapsody Documentation',
            'description'   => 'Learn how to handle file uploads with validation, mime type checking, and size limits.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the caching documentation.
     */
    public function caching(Request $request): Response
    {
        return $this->view('@core/docs/caching.twig', [], [
            'title'         => 'Performance & Caching – Rhapsody Documentation',
            'description'   => 'Learn how to use the caching system to improve performance with file-based and in-memory caches.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the updating documentation.
     */
    public function updating(Request $request): Response
    {
        return $this->view('@core/docs/updating.twig', [], [
            'title'         => 'Updating the Framework – Rhapsody Documentation',
            'description'   => 'Learn how to update Rhapsody to the latest version and manage migrations and dependencies.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the security documentation.
     */
    public function security(Request $request): Response
    {
        return $this->view('@core/docs/security.twig', [], [
            'title'         => 'Security (CSRF, XSS, etc.) – Rhapsody Documentation',
            'description'   => 'Learn about built-in security features like CSRF protection, input sanitisation, and best practices.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the performance documentation.
     */
    public function performance(Request $request): Response
    {
        return $this->view('@core/docs/performance.twig', [], [
            'title'         => 'Performance Optimisation – Rhapsody Documentation',
            'description'   => 'Tips and techniques for optimising your Rhapsody application for speed and scalability.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the logging documentation.
     */
    public function logging(Request $request): Response
    {
        return $this->view('@core/docs/logging.twig', [], [
            'title'         => 'Logging – Rhapsody Documentation',
            'description'   => 'Learn how to use the logging system to track errors, debug, and monitor your application.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the image processing documentation.
     */
    public function imageProcessing(Request $request): Response
    {
        return $this->view('@core/docs/image-processing.twig', [], [
            'title'         => 'Image Processing – Rhapsody Documentation',
            'description'   => 'Learn how to resize, crop, and manipulate images using the ImageProcessor helper.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the events documentation.
     */
    public function events(Request $request): Response
    {
        return $this->view('@core/docs/events.twig', [], [
            'title'         => 'Events & Listeners – Rhapsody Documentation',
            'description'   => 'Learn how to use the event system to decouple your code and respond to application events.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the debugging helpers documentation.
     */
    public function debugging(Request $request): Response
    {
        return $this->view('@core/docs/debugging.twig', [], [
            'title'         => 'Debugging Helpers – Rhapsody Documentation',
            'description'   => 'Learn how to use dd(), dump(), and other debugging tools to inspect your application state.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the configuration documentation.
     */
    public function configuration(Request $request): Response
    {
        return $this->view('@core/docs/configuration.twig', [], [
            'title'         => 'Application Configuration – Rhapsody Documentation',
            'description'   => 'Learn how to configure your application using .env, config files, and service containers.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    /**
     * Shows the error handling documentation.
     */
    public function errorHandling(Request $request): Response
    {
        return $this->view('@core/docs/error-handling.twig', [], [
            'title'         => 'Error Handling & Logging – Rhapsody Documentation',
            'description'   => 'Learn how to handle exceptions, custom error pages, and log errors effectively.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    public function ddosProtection(Request $request): Response
    {
        return $this->view('@core/docs/ddos-protection.twig', [], [
            'title'         => 'DDoS Protection – Rhapsody Documentation',
            'description'   => 'Learn how the built-in DDoS protection works and how to configure it.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    public function authentication(Request $request): Response
    {
        return $this->view('@core/docs/authentication.twig', [], [
            'title'         => 'User Authentication – Rhapsody Documentation',
            'description'   => 'Learn about the built-in login and registration authentication systems.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    public function consoleCommands(Request $request): Response
    {
        return $this->view('@core/docs/console-commands.twig', [], [
            'title'         => 'Console Commands – Rhapsody Documentation',
            'description'   => 'Complete list of Rhapsody CLI commands with examples.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    public function recaptcha(Request $request): Response
    {
        return $this->view('@core/docs/recaptcha.twig', [], [
            'title'         => 'Recaptcha – Rhapsody Documentation',
            'description'   => 'How to seamlessly implement Recaptcha in your forms',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    public function testing(Request $request): Response
    {
        return $this->view('@core/docs/testing.twig', [], [
            'title'         => 'Unit Testing – Rhapsody Documentation',
            'description'   => 'Unit testing made easy!',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }
}
