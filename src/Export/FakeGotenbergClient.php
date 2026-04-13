<?php

namespace App\Export;

/**
 * HRM-T273 — Test double for GotenbergClientInterface.
 *
 * In normal mode returns a minimal valid PDF.
 * Call shouldFail() to configure the next call to throw an exception,
 * allowing functional tests to simulate Gotenberg failures.
 */
final class FakeGotenbergClient implements GotenbergClientInterface
{
    private ?GotenbergException $nextException = null;

    /** @var list<array{filename: string}> */
    private array $calls = [];

    /**
     * Configure the client to throw the given exception on the next convertHtmlToPdf() call.
     * After the exception is thrown the client resets to success mode.
     */
    public function shouldFail(GotenbergException $exception): void
    {
        $this->nextException = $exception;
    }

    /**
     * Reset to success mode (default state).
     */
    public function reset(): void
    {
        $this->nextException = null;
        $this->calls         = [];
    }

    /**
     * @return list<array{filename: string}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    public function convertHtmlToPdf(string $htmlContent, string $filename): string
    {
        $this->calls[] = ['filename' => $filename];

        if ($this->nextException !== null) {
            $exception            = $this->nextException;
            $this->nextException  = null;

            throw $exception;
        }

        // Return a minimal %PDF- magic-bytes string as a stub
        return "%PDF-1.4\n%%EOF\n";
    }
}
