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
  -> TimeWindowFilter
  -> CameraRegistry
  -> DahuaCamera
  -> MaxMessenger
  -> WebhookLogger
```

Основная идея:

1. Камера или регистратор вызывает webhook.
2. Приложение проверяет, что запрос разрешен.
3. Приложение проверяет, не является ли событие дублем.
4. Приложение проверяет, входит ли событие в разрешенное время отправки.
5. Приложение получает snapshot с камеры.
6. Приложение отправляет snapshot в MAX.
7. Приложение пишет диагностический лог.

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
- `Database`
- `WebhookLogger`
- `DuplicateGuard`
- `CameraRegistry`
- `TimeWindowFilter`
- `DahuaCamera`
- `MaxMessenger`
- `EventMessageFormatter`
- `ProfileRepository`
- `ProfileController`

`ProfileController` подключает PHP-представления из:

```text
resources/views/profile
```

Bootstrap подключается локально:

```text
public/assets/vendor/bootstrap/css/bootstrap.min.css
public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js
```

Пример:

```php
$logger = $this->container->logger();
$config = $this->container->config();
$camera = $this->container->camera($request->source());
$max = $this->container->maxMessenger();
$profile = $this->container->profileController();
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

9. Проверить временное окно отправки.

10. Получить snapshot.

11. Загрузить snapshot в MAX.

12. Отправить сообщение в MAX.

13. Записать лог.

14. Вернуть `OK`.

Текст сообщения для MAX формируется через `EventMessageFormatter`. Он делает короткое клиентское сообщение, а технические значения `event`, `source` и `rule` остаются в диагностическом логе.

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

Сам `WebhookRequest` не формирует текст сообщения для MAX. Он только предоставляет технические значения для других классов.

Основные методы:

```php
eventName()
source()
rule()
secret()
duplicateKey()
snapshotFilename()
toLogContext()
```

## EventMessageFormatter

Файл:

```text
src/Webhook/EventMessageFormatter.php
```

`EventMessageFormatter` формирует короткое русское сообщение для MAX.

Сообщение рассчитано на обычного клиента: в нем нет технических полей `event`, `source`, `rule`. Эти детали остаются в диагностическом логе.

Пример:

```text
🚨 Пересечение линии
📍 Ворота
🕒 22.05 08:43 МСК
```

Известные значения переводятся явно:

- `ivs` -> `Событие аналитики`
- `line_crossing` -> `Пересечение линии`
- `intrusion` -> `Вторжение в область`
- `motion` -> `Движение`

Неизвестные значения приводятся к более читаемому виду: `_` и `-` заменяются пробелами.

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
MYSQL_DATABASE
MYSQL_USER
MYSQL_PASSWORD
PROFILE_USERNAME
PROFILE_PASSWORD_HASH
DUPLICATE_TTL_SECONDS
NOTIFY_ALLOWED_FROM
NOTIFY_ALLOWED_TO
```

`MAX_BOT_TOKEN` и `WEBHOOK_SECRET` не являются обязательными переменными `.env`: они задаются в `/profile` и хранятся в MySQL.

Камеры, клиенты MAX, связи камер с клиентами и настройки сервиса читаются из MySQL.

Таблицы:

```text
profile_settings
clients
cameras
camera_clients
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

`NOTIFY_ALLOWED_FROM` и `NOTIFY_ALLOWED_TO` задают разрешенное время отправки уведомлений в формате `HH:MM`. Если обе переменные пустые, временной фильтр выключен.

## Проверка WEBHOOK_SECRET

Входящий webhook должен содержать параметр:

```text
secret
```

Пример:

```text
/webhook?secret=...&event=ivs&source=gate&rule=line_crossing
```

Приложение сравнивает секрет из запроса с `webhook_secret` из таблицы `profile_settings`:

```php
hash_equals($settings->webhookSecret, $request->secret())
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

## TimeWindowFilter

Файл:

```text
src/Event/TimeWindowFilter.php
```

`TimeWindowFilter` проверяет, можно ли сейчас отправлять уведомление в MAX.

Настройки:

```env
NOTIFY_ALLOWED_FROM=08:00
NOTIFY_ALLOWED_TO=23:00
```

Алгоритм:

1. Если обе переменные пустые, фильтр выключен.
2. Если время задано некорректно, событие не отправляется, а причина видна в логе.
3. Если текущее время входит в окно, приложение продолжает поток: snapshot и MAX.
4. Если текущее время вне окна, приложение пишет лог и возвращает:

```text
OK time window skipped
```

Окно может пересекать полночь:

```env
NOTIFY_ALLOWED_FROM=22:00
NOTIFY_ALLOWED_TO=07:00
```

Временной фильтр стоит после защиты от дублей и до получения snapshot. Поэтому вне разрешенного времени камера и MAX не вызываются.

## CameraRegistry

Файлы:

```text
src/Camera/CameraRegistry.php
src/Camera/CameraSource.php
```

`CameraRegistry` выбирает настройки камеры по `source` из webhook.

Пример:

```text
/webhook?secret=...&event=ivs&source=gate&rule=line_crossing
```

`source=gate` будет искать настройки:

```text
cameras.source = gate
```

`source` остается техническим ключом латиницей. В личном кабинете его можно не вводить руками: форма сформирует `source` из названия камеры, например `Прихожая` -> `prihozhaya`.

Snapshot URL тоже можно не собирать вручную. В `/profile` достаточно указать IP/host камеры и номер канала, а форма подставит URL вида:

```text
http://camera-ip/cgi-bin/snapshot.cgi?channel=1
```

Сервер повторяет эту сборку при сохранении, поэтому данные корректно обработаются даже без JavaScript. Название камеры, snapshot URL, доступы Dahua, разрешенные `rule` и получатели MAX берутся из MySQL.

Кабинет также формирует готовую webhook-команду для Dahua:

```text
/w?s=...&e=ivs&c=prihozhaya&r=line_crossing
```

Это короткий формат для камер, где поле команды ограничено по длине. Длинный формат `/webhook?secret=...&event=ivs&source=prihozhaya&rule=line_crossing` также поддерживается для совместимости.

Если у камеры выбраны разрешенные `rule`, команды строятся только для этих правил. Если список правил пустой, приложение принимает любые правила, а кабинет показывает команды для всех известных типов событий.

Получатели MAX задаются через привязку камеры к клиентам:

```text
camera_clients.camera_id
camera_clients.client_id
```

Глобального fallback-получателя нет. Если у камеры нет привязанных клиентов, она не попадает в `CameraRegistry`.

`cameras.allowed_rules` задает список разрешенных правил через запятую. Если список пустой, разрешены все правила.

```text
vehicle_detection,human_detection
```

Если пришел `rule`, которого нет в списке, приложение пишет событие в лог и возвращает:

```text
OK rule skipped
```

Snapshot и MAX при этом не вызываются.

Если источник не найден, приложение пишет это в лог и возвращает:

```text
OK unknown source skipped
```

Snapshot и MAX при этом не вызываются.

## DahuaCamera

Файлы:

```text
src/Camera/DahuaCamera.php
src/Camera/SnapshotResult.php
```

`DahuaCamera` получает snapshot по URL, который передал `CameraRegistry`.

URL берется из поля `snapshot_url` конкретной камеры в MySQL:

```text
cameras.snapshot_url
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

`chat_id` выбирается так:

1. `ProfileRepository` находит клиентов, привязанных к камере через `camera_clients`.
2. Для каждого найденного `clients.max_chat_id` приложение отправляет отдельное сообщение.
3. Если у камеры нет клиентов, она не попадает в `CameraRegistry`, и событие с таким `source` будет пропущено как неизвестный источник.

Как получить `chat_id` клиента, описано в:

```text
docs/getChatIdMax.md
```

## WebhookLogger

Файл:

```text
src/Logger/WebhookLogger.php
```

`WebhookLogger` пишет диагностический лог в:

```text
storage/logs/webhook.log
```

Лог хранит только последние 40 записей, чтобы файл не рос бесконечно.

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
    "chat_id": "111111",
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

### 5. Вне временного окна

Если настроено окно отправки и событие пришло вне него:

```text
OK time window skipped
```

Snapshot и MAX не вызываются.

### 6. Успешный сценарий

```text
GET /webhook?secret=...&event=ivs&source=gate&rule=line_crossing
```

Результат:

1. Проверен secret.
2. Проверен дубль.
3. Проверено временное окно.
4. Выбрана камера и список MAX chat_id по `source`.
5. Получен snapshot.
6. Snapshot загружен в MAX.
7. Короткое сообщение с фото отправлено.
8. Лог записан.
9. Ответ:

```text
OK
```

## Как расширять под несколько камер

Текущая версия уже использует `source` и умеет выбирать камеру через `CameraRegistry`.

Пример логики:

```php
$cameraSource = $cameraRegistry->find($request->source());
$camera = new DahuaCamera($cameraSource->snapshotUrl, $cameraSource->user, $cameraSource->password);
```

Для NVR можно завести source на каждый канал через `/profile`:

```text
/webhook?secret=...&event=ivs&source=nvr_ch3&rule=line_crossing
```

И сохранить в MySQL:

```text
source: nvr_ch3
snapshot_url: http://nvr-ip/cgi-bin/snapshot.cgi?channel=3
```
