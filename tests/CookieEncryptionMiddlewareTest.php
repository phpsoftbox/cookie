<?php

declare(strict_types=1);

namespace PhpSoftBox\Cookie\Tests;

use PhpSoftBox\Cookie\CookieMiddleware;
use PhpSoftBox\Cookie\CookieQueue;
use PhpSoftBox\Cookie\SetCookie;
use PhpSoftBox\Encryptor\Encryptor;
use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function explode;
use function rawurldecode;

#[CoversClass(CookieMiddleware::class)]
#[CoversMethod(CookieMiddleware::class, 'process')]
final class CookieEncryptionMiddlewareTest extends TestCase
{
    /**
     * Проверяем, что middleware расшифровывает входящие cookie.
     */
    #[Test]
    public function testDecryptsIncomingCookies(): void
    {
        $encryptor = new Encryptor(defaultKey: 'secret-key');
        $middleware = new CookieMiddleware(
            queue: new CookieQueue(),
            encryptor: $encryptor,
            except: ['XSRF-TOKEN'],
        );

        $encrypted = $encryptor->encryptWithCurrentKey('value');
        $request = (new ServerRequest('GET', 'https://example.com/'))
            ->withCookieParams([
                'foo' => $encrypted,
                'XSRF-TOKEN' => 'plain',
            ]);

        $captured = ['foo' => null, 'xsrf' => null];

        $handler = new class ($captured) implements RequestHandlerInterface {
            public function __construct(private array &$captured)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $cookies = $request->getCookieParams();
                $this->captured['foo'] = $cookies['foo'] ?? null;
                $this->captured['xsrf'] = $cookies['XSRF-TOKEN'] ?? null;

                return new Response(200);
            }
        };

        $middleware->process($request, $handler);

        $this->assertSame('value', $captured['foo']);
        $this->assertSame('plain', $captured['xsrf']);
    }

    /**
     * Проверяем, что middleware шифрует исходящие cookie.
     */
    #[Test]
    public function testEncryptsOutgoingCookies(): void
    {
        $encryptor = new Encryptor(defaultKey: 'secret-key');
        $middleware = new CookieMiddleware(
            queue: new CookieQueue(),
            encryptor: $encryptor,
            except: ['XSRF-TOKEN'],
        );

        $request = new ServerRequest('GET', 'https://example.com/');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $queue = $request->getAttribute('cookie_queue');
                if ($queue instanceof CookieQueue) {
                    $queue->queue(SetCookie::create('foo', 'bar'));
                    $queue->queue(SetCookie::create('XSRF-TOKEN', 'token'));
                }

                return new Response(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $headers = $response->getHeader('Set-Cookie');
        $this->assertCount(2, $headers);

        $fooHeader = $this->findHeaderByName($headers, 'foo');
        $xsrfHeader = $this->findHeaderByName($headers, 'XSRF-TOKEN');

        $fooValue = $this->extractCookieValue($fooHeader);
        $xsrfValue = $this->extractCookieValue($xsrfHeader);

        $this->assertSame('bar', $encryptor->decryptWithAnyKey($fooValue));
        $this->assertSame('token', $xsrfValue);
    }

    private function extractCookieValue(string $header): string
    {
        $pair = explode(';', $header, 2)[0];
        $value = explode('=', $pair, 2)[1] ?? '';

        return rawurldecode($value);
    }

    /**
     * @param list<string> $headers
     */
    private function findHeaderByName(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, $name . '=')) {
                return $header;
            }
        }

        return $headers[0] ?? '';
    }
}
