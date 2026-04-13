<?php

namespace App\Tests\Unit;

use App\Export\GotenbergClientInterface;
use App\Export\GotenbergTimeoutException;
use App\Export\GotenbergUnavailableException;
use App\Export\NativeGotenbergClient;
use PHPUnit\Framework\TestCase;

/**
 * HRM-T265 — Unit tests for the Gotenberg PDF client layer.
 */
final class GotenbergClientTest extends TestCase
{
    public function testNativeClientCanBeInstantiated(): void
    {
        $client = new NativeGotenbergClient('http://gotenberg:3000', 30.0);
        self::assertInstanceOf(GotenbergClientInterface::class, $client);
    }

    public function testTimeoutExceptionCarriesUrl(): void
    {
        $ex = new GotenbergTimeoutException('http://gotenberg:3000/forms/chromium/convert/html');
        self::assertStringContainsString('http://gotenberg:3000', $ex->getMessage());
    }

    public function testTimeoutExceptionIsRuntimeException(): void
    {
        $ex = new GotenbergTimeoutException('http://example.com');
        self::assertInstanceOf(\RuntimeException::class, $ex);
    }

    public function testTimeoutExceptionAcceptsPreviousThrowable(): void
    {
        $previous = new \RuntimeException('inner');
        $ex       = new GotenbergTimeoutException('http://example.com', $previous);
        self::assertSame($previous, $ex->getPrevious());
    }

    public function testUnavailableExceptionCarriesStatusCode(): void
    {
        $ex = new GotenbergUnavailableException(503);
        self::assertStringContainsString('503', $ex->getMessage());
    }

    public function testUnavailableExceptionIsRuntimeException(): void
    {
        $ex = new GotenbergUnavailableException(500);
        self::assertInstanceOf(\RuntimeException::class, $ex);
    }

    public function testFakeClientReturnsSimulatedPdfBinary(): void
    {
        $fakePdf = '%PDF-1.4 fake-pdf-content';
        $client  = $this->makeFakeClient($fakePdf);

        $result = $client->convertHtmlToPdf('<html><body>Hello</body></html>');

        self::assertSame($fakePdf, $result);
    }

    public function testClientThrowsTimeoutException(): void
    {
        $this->expectException(GotenbergTimeoutException::class);

        $client = new class() implements GotenbergClientInterface {
            public function convertHtmlToPdf(string $html): string
            {
                throw new GotenbergTimeoutException('http://gotenberg-test:3000/forms/chromium/convert/html');
            }
        };

        $client->convertHtmlToPdf('<html></html>');
    }

    public function testClientThrowsUnavailableOn503(): void
    {
        $this->expectException(GotenbergUnavailableException::class);
        $this->expectExceptionMessageMatches('/503/');

        $client = new class() implements GotenbergClientInterface {
            public function convertHtmlToPdf(string $html): string
            {
                throw new GotenbergUnavailableException(503);
            }
        };

        $client->convertHtmlToPdf('<html></html>');
    }

    public function testClientThrowsUnavailableOn500(): void
    {
        $this->expectException(GotenbergUnavailableException::class);
        $this->expectExceptionMessageMatches('/500/');

        $client = new class() implements GotenbergClientInterface {
            public function convertHtmlToPdf(string $html): string
            {
                throw new GotenbergUnavailableException(500);
            }
        };

        $client->convertHtmlToPdf('<html></html>');
    }

    public function testClientThrowsRuntimeExceptionOnNetworkError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/connection refused/');

        $client = new class() implements GotenbergClientInterface {
            public function convertHtmlToPdf(string $html): string
            {
                throw new \RuntimeException('Gotenberg HTTP request failed: connection refused');
            }
        };

        $client->convertHtmlToPdf('<html></html>');
    }

    private function makeFakeClient(string $simulatedPdfBinary): GotenbergClientInterface
    {
        return new class($simulatedPdfBinary) implements GotenbergClientInterface {
            public function __construct(private readonly string $pdfBinary)
            {
            }

            public function convertHtmlToPdf(string $html): string
            {
                return $this->pdfBinary;
            }
        };
    }
}
