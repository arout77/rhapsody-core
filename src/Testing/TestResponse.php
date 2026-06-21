<?php
namespace Rhapsody\Core\Testing;

use PHPUnit\Framework\Assert;

class TestResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $content,
        public readonly array $headers = []
    ) {}

    public function assertOk(): self
    {
        Assert::assertEquals(200, $this->status, "Expected status code 200 but received {$this->status}.");
        return $this;
    }

    public function assertStatus(int $code): self
    {
        Assert::assertEquals($code, $this->status, "Expected status code {$code} but received {$this->status}.");
        return $this;
    }

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->content, "Failed asserting that response contains '{$text}'.");
        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->content, "Failed asserting that response does not contain '{$text}'.");
        return $this;
    }

    public function assertHeader(string $header, ?string $value = null): self
    {
        $normalizedHeaders = array_change_key_case($this->headers);
        $searchHeader      = strtolower($header);

        Assert::assertArrayHasKey($searchHeader, $normalizedHeaders, "Header [{$header}] not found on response.");

        if ($value !== null) {
            Assert::assertEquals($value, $normalizedHeaders[$searchHeader]);
        }

        return $this;
    }
}
