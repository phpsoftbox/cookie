# Cookie

Компонент для работы с Cookie и Set-Cookie.

## Пример

```php
use PhpSoftBox\Cookie\CookieJar;
use PhpSoftBox\Cookie\SetCookie;

$cookies = CookieJar::fromHeader('a=1; b=2');

$setCookie = SetCookie::create('sid', 'token')
    ->withHttpOnly(true)
    ->withSecure(true);

$headers = CookieJar::toHeaders([$setCookie]);
```

## Middleware

```php
use PhpSoftBox\Cookie\CookieMiddleware;
use PhpSoftBox\Cookie\CookieQueue;
use PhpSoftBox\Cookie\SetCookie;

$queue = new CookieQueue();
$middleware = new CookieMiddleware($queue);

$queue->queue(SetCookie::create('token', 'abc'));
```

### Шифрование cookie

Можно включить шифрование значений cookie через `phpsoftbox/encryptor`.
Для исключений (например, сессионная cookie или `XSRF-TOKEN`) передайте список `except`.

```php
use PhpSoftBox\Cookie\CookieMiddleware;
use PhpSoftBox\Cookie\CookieQueue;
use PhpSoftBox\Encryptor\Encryptor;

$encryptor = new Encryptor(defaultKey: $_ENV['APP_KEY'] ?? null);

$middleware = new CookieMiddleware(
    queue: new CookieQueue(),
    encryptor: $encryptor,
    except: ['XSRF-TOKEN', 'psb_session'],
);
```

Зашифрованное значение привязано к имени cookie (связанные данные `cookie:<имя>`): значение одной cookie не
расшифруется под именем другой. Значения, зашифрованные до версии 1.0, после обновления не расшифровываются —
такие cookie отбрасываются, пользователю придётся войти заново.

### Долгоживущие процессы

`CookieQueue` обычно общий на процесс (синглтон контейнера). `CookieMiddleware` очищает очередь в начале запроса и
забирает её в `finally`: cookie запроса, завершившегося исключением, не уходят следующему пользователю.

## Проверка атрибутов

`SetCookie` отклоняет (`InvalidArgumentException`) имя вне token из RFC 6265 (пробелы, `;`, `=`, CR/LF), путь с `;`
или управляющими символами и домен, который не является именем хоста.
