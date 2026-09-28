<?php
namespace Rhapsody\Core;

class RedirectResponse extends Response
{
    protected string $url;
    protected int $redirectCode;

    public function __construct(string $url, int $redirectCode = 302): void
    {
        if ($redirectCode < 300 || $redirectCode > 399) {
            throw new \InvalidArgumentException("Invalid redirect status code: {$redirectCode}");
        }

        $this->url          = $url;
        $this->redirectCode = $redirectCode;
        $this->setStatusCode($redirectCode);
    }

    public function with(string $key, string $message): self
    {
        Session::flash($key, $message);
        return $this;
    }

    public function send(): void
    {
        Session::close();
        header('Location: ' . $this->url, true, $this->redirectCode);
        exit();
    }
}
