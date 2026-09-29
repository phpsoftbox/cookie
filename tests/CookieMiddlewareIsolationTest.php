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
use RuntimeException;

#[CoversClass(CookieMiddleware::class)]
#[CoversMethod(CookieMiddleware::class, 'process')]
final class CookieMiddlewareIsolationTest extends TestCase
{
    private const string KEY = 'cookie-test-key-0123456789abcdef012345';

    /**
     * Проверим, что cookie, поставленные в очередь запросом, который завершился исключением, не уходят следующему
     * запросу через общую очередь.
     *
     * @see CookieMiddleware::process()
     */
    #[Test]
    public function discardsQueueWhenHandlerThrows(): void
    {
        $queue = new CookieQueue();

        $middleware = new CookieMiddleware(queue: $queue);

        try {
            $middleware->process(new ServerRequest('GET', 'https://example.com/'), $this->handler(static function () use ($queue): never {
                $queue->queue(SetCookie::create('psb_session', 'victim-session'));

                throw new RuntimeException('Handler failed.');
            }));
            self::fail('Exception expected.');
        } catch (RuntimeException) {
            // ожидаемо
        }

        $response = $middleware->process(new ServerRequest('GET', 'https://example.com/'), $this->handler(static fn (): null => null));

        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    /**
     * Проверим, что cookie, поставленные в общую очередь вне запроса, не попадают в ответ.
     *
     * @see CookieMiddleware::process()
     */
    #[Test]
    public function ignoresCookiesQueuedBeforeRequest(): void
    {
        $queue = new CookieQueue();

        $queue->queue(SetCookie::create('remember_web', 'stale-token'));

        $response = new CookieMiddleware(queue: $queue)->process(
            new ServerRequest('GET', 'https://example.com/'),
            $this->handler(static fn (): null => null),
        );

        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    /**
     * Проверим, что зашифрованное значение одной cookie не принимается под именем другой.
     *
     * @see CookieMiddleware::process()
     */
    #[Test]
    public function rejectsValueCopiedFromAnotherCookie(): void
    {
        $encryptor = new Encryptor(defaultKey: self::KEY);
        $captured  = [];

        $request = new ServerRequest('GET', 'https://example.com/')->withCookieParams([
            'remember_site' => $encryptor->encryptWithCurrentKey('42|token', 'cookie:remember_web'),
            'remember_web'  => $encryptor->encryptWithCurrentKey('42|token', 'cookie:remember_web'),
        ]);

        new CookieMiddleware(encryptor: $encryptor)->process($request, $this->handler(
            static function (ServerRequestInterface $request) use (&$captured): null {
                $captured = $request->getCookieParams();

                return null;
            },
        ));

        self::assertSame(['remember_web' => '42|token'], $captured);
    }

    /**
     * @param callable(ServerRequestInterface): null $callback
     */
    private function handler(callable $callback): RequestHandlerInterface
    {
        return new readonly class ($callback) implements RequestHandlerInterface {
            /**
             * @param callable(ServerRequestInterface): null $callback
             */
            public function __construct(
                private mixed $callback,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->callback)($request);

                return new Response(200);
            }
        };
    }
}
