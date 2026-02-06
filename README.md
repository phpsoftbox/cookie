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
