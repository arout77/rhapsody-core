<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\BaseController;
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
    public function index(): Response
    {
        return $this->view('@core/docs/index.twig');
    }

    /**
     * Shows the installation and setup guide.
     */
    public function installation(): Response
    {
        return $this->view('@core/docs/installation.twig');
    }

    /**
     * Shows the routing documentation.
     */
    public function routing(): Response
    {
        return $this->view('@core/docs/routing.twig');
    }

    /**
     * @return mixed
     */
    public function request(): Response
    {
        return $this->view('@core/docs/request.twig');
    }

    /**
     * @return mixed
     */
    public function response(): Response
    {
        return $this->view('@core/docs/response.twig');
    }

    /**
     * Shows the controllers documentation.
     */
    public function controllers(): Response
    {
        return $this->view('@core/docs/controllers.twig');
    }

    /**
     * Shows the models and database documentation.
     */
    public function models(): Response
    {
        return $this->view('@core/docs/models.twig');
    }

    /**
     * @return mixed
     */
    public function doctrine(): Response
    {
        return $this->view('@core/docs/doctrine.twig');
    }

    /**
     * Shows the views and templating documentation.
     */
    public function views(): Response
    {
        return $this->view('@core/docs/views.twig');
    }

    /**
     * Shows the validation documentation.
     */
    public function validation(): Response
    {
        return $this->view('@core/docs/validation.twig');
    }

    /**
     * Shows the authentication and middleware documentation.
     */
    public function middleware(): Response
    {
        return $this->view('@core/docs/middleware.twig');
    }

    /**
     * @return mixed
     */
    public function cli(): Response
    {
        return $this->view('@core/docs/cli.twig');
    }

    /**
     * @return mixed
     */
    public function mailer(): Response
    {
        return $this->view('@core/docs/mailer.twig');
    }

    /**
     * @return mixed
     */
    public function seo(): Response
    {
        return $this->view('@core/docs/seo.twig');
    }

    /**
     * @return mixed
     */
    public function themes(): Response
    {
        $meta = [
            'title'       => "Theme Customization & Architecture Docs - Rhapsody Framework",
            'description' => "Learn how to extend layouts, customize styles, and build completely decoupled theme front-ends using Twig template inheritance in Rhapsody.",
        ];
        return $this->view('@core/docs/themes.twig', [], $meta);
    }

    /**
     * @return mixed
     */
    public function pagination(): Response
    {
        return $this->view('@core/docs/pagination.twig');
    }

    /**
     * @return mixed
     */
    public function fileUploader(): Response
    {
        return $this->view('@core/docs/file-uploader.twig');
    }

    /**
     * @return mixed
     */
    public function caching(): Response
    {
        return $this->view('@core/docs/caching.twig');
    }

    /**
     * @return mixed
     */
    public function updating(): Response
    {
        return $this->view('@core/docs/updating.twig');
    }

    /**
     * @return mixed
     */
    public function security(): Response
    {
        return $this->view('@core/docs/security.twig');
    }

    /**
     * @return mixed
     */
    public function performance(): Response
    {
        return $this->view('@core/docs/performance.twig');
    }

    /**
     * @return mixed
     */
    public function logging(): Response
    {
        return $this->view('@core/docs/logging.twig');
    }

    /**
     * @return mixed
     */
    public function imageProcessing()
    {
        return $this->view('@core/docs/image-processing.twig');
    }

    /**
     * Shows the events documentation.
     */
    public function events(): Response
    {
        return $this->view('@core/docs/events.twig');
    }

    public function debugging(): Response
    {
        return $this->view('@core/docs/debugging.twig');
    }

    public function configuration(): Response
    {
        return $this->view('@core/docs/configuration.twig');
    }

    public function errorHandling(): Response
    {
        return $this->view('@core/docs/error-handling.twig');
    }
}
