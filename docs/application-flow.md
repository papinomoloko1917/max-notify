# Поток работы приложения

Этот документ описывает, как работает Max Notify изнутри: какие классы участвуют, в каком порядке выполняются шаги, где принимаются решения и что происходит при ошибках.

## Общая схема

```text
Dahua/NVR
  -> HTTP GET /webhook
  -> public/index.php
  -> App
  -> WebhookRequest
  -> AppConfig
  -> DuplicateGuard
  -> DahuaCamera
  -> MaxMessenger
  -> WebhookLogger
```

Основная идея:

1. Камера или регистратор вызывает webhook.
2. Приложение проверяет, что запрос разрешен.
3. Приложение проверяет, не является ли событие дублем.
4. Приложение получает snapshot с камеры.
5. Приложение отправляет snapshot в MAX.
6. Приложение пишет диагностический лог.

## Entry point

Файл:

```text
public/index.php
```

Это единственная публичная точка входа. Nginx направляет все запросы в этот файл.

Код в `index.php` минимальный:

```php
$app = new App(new Container(APP_PATH));
$app->handle();
```

Задача `index.php`:

- определить `APP_PATH`;
- подключить Composer autoload;
- создать приложение;
- передать управление в `App`.

Вся основная логика находится не в `index.php`, а в `src/App.php`.

## Container

Файл:

```text
src/Container/Container.php
```

`Container` - простой псевдоконтейнер зависимостей.

Он отвечает за создание и переиспользование основных объектов:

- `AppConfig`
- `WebhookLogger`
- `DuplicateGuard`
- `DahuaCamera`
- `MaxMessenger`

Пример:

```php
$logger = $this->container->logger();
$config = $this->container->config();
$camera = $this->container->camera();
$max = $this->container->maxMessenger();
```

Зачем нужен контейнер:

- `App` не знает, как именно создаются классы;
- вся сборка зависимостей находится в одном месте;
- проект легче расширять;
- позже можно заменить этот простой контейнер на полноценный DI-container.

## App

Файл:

```text
src/App.php
```

`App` описывает основной поток приложения.

Порядок выполнения:

1. Установить HTTP-ответ по умолчанию:

```php
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
```

2. Получить зависимости:

```php
$logger = $this->container->logger();
$config = $this->container->config();
```

3. Создать объект входящего запроса:

```php
$request = WebhookRequest::fromGlobals();
```

4. Сформировать базовый контекст лога:

```php
$event = $request->toLogContext();
```

5. Если это `/health`, сразу ответить `OK`.

6. Проверить обязательные настройки.

7. Проверить `WEBHOOK_SECRET`.

8. Проверить дубль события.

9. Получить snapshot.

10. Загрузить snapshot в MAX.

11. Отправить сообщение в MAX.

12. Записать лог.

13. Вернуть `OK`.

## Health check

URL:

```text
/health
```

Для `/health` приложение не проверяет секрет, не обращается к камере и не обращается к MAX.

Ответ:

```text
OK
```

Этот endpoint нужен, чтобы быстро проверить:

- Nginx работает;
- PHP работает;
- приложение загружается без fatal error.

## WebhookRequest

Файл:

```text
src/Webhook/WebhookRequest.php
```

`WebhookRequest` отвечает за входящий HTTP-запрос.

Он читает:

- `$_SERVER`
- `$_GET`
- `$_POST`
- headers
- raw body

Основные методы:

```php
eventName()
source()
rule()
secret()
duplicateKey()
snapshotFilename()
messageText()
toLogContext()
```

### source

`source` - это имя источника события.

Сначала приложение ищет параметр:

```text
source
```

Если его нет, используется старый параметр:

```text
camera
```

Если нет ни `source`, ни `camera`, используется:

```text
default
```

Это сделано для подготовки к нескольким камерам.

Примеры:

```text
/webhook?source=gate
/webhook?source=yard
/webhook?camera=dahua
```

### rule

`rule` - имя правила события.

Например:

```text
line_crossing
intrusion
```

`source` и `rule` проходят очистку. Разрешены только:

```text
a-z A-Z 0-9 _ -
```

Остальные символы заменяются на `_`.

Это нужно, потому что эти значения используются в:

- duplicate key;
- имени snapshot-файла;
- тексте сообщения.

### duplicate key

Ключ дубля строится так:

```text
event:source:rule
```

Пример:

```text
ivs:gate:line_crossing
```

## AppConfig

Файл:

```text
src/Config/AppConfig.php
```

`AppConfig` читает настройки из переменных окружения.

Основные переменные:

```env
MAX_BOT_TOKEN
MAX_CHAT_ID
DAHUA_CAMERA_URL
DAHUA_CAMERA_USER
DAHUA_CAMERA_PASSWORD
WEBHOOK_SECRET
DUPLICATE_TTL_SECONDS
```

Метод:

```php
missingValues()
```

проверяет, что обязательные строковые настройки не пустые.

`DUPLICATE_TTL_SECONDS` читается как положительное число. Если переменная не задана или задана некорректно, используется значение по умолчанию:

```text
5
```

## Проверка WEBHOOK_SECRET

Входящий webhook должен содержать параметр:

```text
secret
```

Пример:

```text
/webhook?secret=...&event=ivs&source=gate&rule=line_crossing
```

Приложение сравнивает секрет из запроса с `WEBHOOK_SECRET` из `.env`:

```php
hash_equals($config->webhookSecret, $request->secret())
```

Если секрет неверный:

1. В лог пишется ошибка:

```json
{
  "message": "Invalid webhook secret"
}
```

2. Камера не дергается.
3. MAX не дергается.
4. Ответ:

```text
403 Forbidden
```

## DuplicateGuard

Файл:

```text
src/Event/DuplicateGuard.php
```

`DuplicateGuard` защищает от повторной обработки одинаковых событий.

Состояние хранится в файле:

```text
storage/logs/duplicate-events.json
```

Алгоритм:

1. Приложение строит duplicate key:

```text
event:source:rule
```

2. `DuplicateGuard` проверяет, когда такой ключ был в последний раз.
3. Если ключ уже был в течение `DUPLICATE_TTL_SECONDS`, событие считается дублем.
4. Дубль пишется в лог, но snapshot и MAX не вызываются.

Ответ на дубль:

```text
OK duplicate skipped
```

Почему ответ все равно `200 OK`: камере не нужно повторять отправку события. Мы приняли запрос, но сознательно не обработали его повторно.

## DahuaCamera

Файлы:

```text
src/Camera/DahuaCamera.php
src/Camera/SnapshotResult.php
```

`DahuaCamera` получает snapshot по URL из `.env`:

```env
DAHUA_CAMERA_URL=http://camera-ip/cgi-bin/snapshot.cgi?channel=1
```

Используется Digest-авторизация:

```php
CURLOPT_HTTPAUTH => CURLAUTH_DIGEST
```

Результат возвращается объектом `SnapshotResult`.

Он содержит:

```php
image
httpCode
error
```

И метод:

```php
isSuccessful()
```

Snapshot считается успешным, если:

```text
httpCode === 200
image !== null
```

Фото не сохраняется на диск.

## MaxMessenger

Файлы:

```text
src/Messenger/MaxMessenger.php
src/Messenger/CreateUploadResult.php
src/Messenger/UploadImageResult.php
src/Messenger/SendMessageResult.php
```

`MaxMessenger` работает с MAX API.

Поток отправки изображения:

1. Создать upload:

```text
POST https://platform-api.max.ru/uploads?type=image
```

2. Загрузить JPEG на upload URL.

3. Отправить сообщение:

```text
POST https://platform-api.max.ru/messages?chat_id=...
```

Изображение передается в MAX из памяти PHP через `CURLStringFile`.

Это позволяет не сохранять snapshot на сервере.

## WebhookLogger

Файл:

```text
src/Logger/WebhookLogger.php
```

`WebhookLogger` пишет диагностический лог в:

```text
storage/logs/webhook.log
```

Формат - JSON-блоки, разделенные строкой:

```text
--------------------------------------------------------------------------------
```

Пример успешного события:

```json
{
  "time": "2026-05-21 13:57:51",
  "method": "GET",
  "uri": "/webhook?secret=%5Bredacted%5D&event=ivs&source=gate&rule=line_crossing",
  "parsed": {
    "event": "ivs",
    "source": "gate",
    "rule": "line_crossing"
  },
  "duplicate": {
    "key": "ivs:gate:line_crossing",
    "is_duplicate": false,
    "ttl_seconds": 5
  },
  "snapshot": {
    "filename": "20260521_135751_gate_line_crossing.jpg",
    "path": null,
    "http_code": 200,
    "error": null
  },
  "max_upload": {
    "http_code": 200,
    "error": null,
    "has_url": true
  },
  "max_image_upload": {
    "http_code": 200,
    "error": null,
    "has_token": true
  },
  "max": {
    "text_http_code": 200,
    "text_error": null,
    "message_id": "mid....",
    "has_attachment": true,
    "response_is_json": true
  }
}
```

## Что не пишется в лог

Для безопасности лог не должен содержать секреты.

Поэтому:

- `secret` в query редактируется;
- `secret` в URI редактируется;
- `Authorization`, `Cookie`, `Set-Cookie` редактируются;
- raw body целиком не пишется;
- полный ответ MAX не пишется.

## Основные ветки выполнения

### 1. Health check

```text
GET /health
```

Результат:

```text
OK
```

Камера и MAX не вызываются.

### 2. Неверный secret

```text
GET /webhook?secret=wrong...
```

Результат:

```text
403 Forbidden
```

Камера и MAX не вызываются.

### 3. Дубль события

```text
GET /webhook?secret=...&event=ivs&source=gate&rule=line_crossing
```

Если такое же событие уже было недавно:

```text
OK duplicate skipped
```

Камера и MAX не вызываются.

### 4. Snapshot не получен

Если камера недоступна или вернула не `200`, событие пишется в лог.

MAX все равно может получить текстовое сообщение без изображения.

### 5. Успешный сценарий

```text
GET /webhook?secret=...&event=ivs&source=gate&rule=line_crossing
```

Результат:

1. Проверен secret.
2. Проверен дубль.
3. Получен snapshot.
4. Snapshot загружен в MAX.
5. Сообщение с фото отправлено.
6. Лог записан.
7. Ответ:

```text
OK
```

## Как расширять под несколько камер

Текущая версия уже использует `source`, но snapshot URL пока один.

Следующий шаг:

```text
src/Camera/CameraSource.php
src/Camera/CameraRegistry.php
```

`CameraRegistry` должен будет по `source` или `channel` выбирать настройки камеры.

Пример будущей логики:

```php
$source = $cameraRegistry->find($request->source());
$camera = new DahuaCamera($source->snapshotUrl, $source->user, $source->password);
```

Для NVR можно будет использовать `channel`:

```text
/webhook?secret=...&event=ivs&source=nvr&channel=3&rule=line_crossing
```

И строить snapshot URL:

```text
http://nvr-ip/cgi-bin/snapshot.cgi?channel=3
```
