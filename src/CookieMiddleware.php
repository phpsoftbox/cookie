<?php

declare(strict_types=1);

namespace PhpSoftBox\Cookie;

use PhpSoftBox\Encryptor\Contracts\EncryptorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function in_array;
use function is_string;

final class CookieMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CookieQueue $queue = new CookieQueue(),
        private readonly string $attribute = 'cookie_queue',
        private readonly bool $parseHeader = true,
        private readonly ?EncryptorInterface $encryptor = null,
        private readonly array $except = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->parseHeader && $request->getCookieParams() === []) {
            $header = $request->getHeaderLine('Cookie');
            if ($header !== '') {
                $parsed = Cookie::parseHeader($header);
                $params = [];
                foreach ($parsed as $cookie) {
                    $params[$cookie->name] = $cookie->value;
                }
                $request = $request->withCookieParams($params);
            }
        }

        $request = $this->decryptRequestCookies($request);

        $request = $request->withAttribute($this->attribute, $this->queue);

        // Очередь общая для процесса (синглтон в долгоживущем воркере): остатки прошлого запроса и cookie запроса,
        // завершившегося исключением, не должны уйти следующему пользователю.
        $this->queue->flush();

        try {
            $response = $handler->handle($request);
        } finally {
            $cookies = $this->queue->flush();
        }

        foreach ($cookies as $cookie) {
            $cookie   = $this->encryptResponseCookie($cookie);
            $response = $response->withAddedHeader('Set-Cookie', $cookie->toHeader());
        }

        return $response;
    }

    private function decryptRequestCookies(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($this->encryptor === null) {
            return $request;
        }

        $cookies = $request->getCookieParams();
        if ($cookies === []) {
            return $request;
        }

        $decrypted = [];
        foreach ($cookies as $name => $value) {
            if ($this->isExcluded($name)) {
                $decrypted[$name] = $value;
                continue;
            }

            if (!is_string($value)) {
                $decrypted[$name] = $value;
                continue;
            }

            try {
                $decrypted[$name] = $this->encryptor->decryptWithAnyKey($value, $this->associatedData((string) $name));
            } catch (Throwable) {
                // ignore invalid cookie values
            }
        }

        return $request->withCookieParams($decrypted);
    }

    private function encryptResponseCookie(SetCookie $cookie): SetCookie
    {
        if ($this->encryptor === null) {
            return $cookie;
        }

        if ($this->isExcluded($cookie->name())) {
            return $cookie;
        }

        $encrypted = $this->encryptor->encryptWithCurrentKey($cookie->value(), $this->associatedData($cookie->name()));

        return $cookie->withValue($encrypted);
    }

    /**
     * Шифртекст привязан к имени cookie: значение одной cookie не расшифруется как другая.
     */
    private function associatedData(string $name): string
    {
        return 'cookie:' . $name;
    }

    private function isExcluded(string $name): bool
    {
        return in_array($name, $this->except, true);
    }
}
