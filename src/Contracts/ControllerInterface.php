<?php
namespace Rhapsody\Core\Contracts;

interface ControllerInterface
{
    /**
     * Optionally set the container for the controller.
     */
    public function setContainer(ContainerInterface $container): void;

    /**
     * Returns the container instance.
     */
    public function getContainer(): ContainerInterface;
}
