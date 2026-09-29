<?php

declare(strict_types=1);

namespace PhpSoftBox\Cookie\Tests;

use InvalidArgumentException;
use PhpSoftBox\Cookie\SetCookie;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetCookie::class)]
#[CoversMethod(SetCookie::class, '__construct')]
#[CoversMethod(SetCookie::class, 'withPath')]
#[CoversMethod(SetCookie::class, 'withDomain')]
final class SetCookieValidationTest extends TestCase
{
    /**
     * Проверим, что имя с разделителями и переводами строк отклоняется: иначе оно дописывает атрибуты Set-Cookie.
     *
     * @see SetCookie::__construct()
     */
    #[Test]
    #[DataProvider('invalidNames')]
    public function rejectsInvalidName(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        SetCookie::create($name, 'value');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'точка с запятой' => ['sid; Domain=evil.com'];
        yield 'перевод строки' => ["sid\r\nSet-Cookie: admin=1"];
        yield 'пробел' => ['my sid'];
        yield 'равно' => ['sid=1'];
    }

    /**
     * Проверим, что путь с `;` или управляющими символами отклоняется.
     *
     * @see SetCookie::withPath()
     */
    #[Test]
    public function rejectsPathWithSeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SetCookie::create('sid', 'value')->withPath('/; Domain=evil.com');
    }

    /**
     * Проверим, что домен допускает только имя хоста с необязательной ведущей точкой.
     *
     * @see SetCookie::withDomain()
     */
    #[Test]
    public function rejectsDomainWithSeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SetCookie::create('sid', 'value')->withDomain("example.com\r\nX-Injected: 1");
    }

    /**
     * Проверим, что корректные имя, путь и домен принимаются и попадают в заголовок.
     *
     * @see SetCookie::withDomain()
     * @see SetCookie::toHeader()
     */
    #[Test]
    public function acceptsValidAttributes(): void
    {
        $header = SetCookie::create('XSRF-TOKEN', 'value')->withPath('/app')->withDomain('.example.com')->toHeader();

        self::assertStringStartsWith('XSRF-TOKEN=value; Path=/app; Domain=.example.com', $header);
    }
}
