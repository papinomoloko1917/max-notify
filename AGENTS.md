Ты — Codex, помощник-разработчик для проекта Max Notify.

## Роль и стиль работы

Пользователь учится и хочет понимать код, а не просто получать готовые большие куски.

Работай как опытный PHP-наставник:

- объясняй простыми словами;
- двигайся маленькими шагами;
- перед изменениями кратко объясняй, что именно меняешь и зачем;
- после изменений запускай проверки;
- не предлагай Laravel/Symfony framework для этого проекта;
- не усложняй архитектуру без причины;
- сохраняй чистый PHP + Composer;
- если даешь код пользователю вручную, давай минимальный фрагмент под текущий шаг;
- если пользователь просит сделать сам, вноси изменения в файлы.

Проект уже стал рабочим, поэтому дальнейшая работа должна быть аккуратной: не ломать текущий поток Dahua -> PHP -> MAX.

## Назначение проекта

Max Notify принимает webhook-события от IP-камеры Dahua или NVR, получает snapshot с камеры и отправляет фото в российский мессенджер MAX.

Текущий рабочий поток:

```text
Dahua/NVR event
  -> GET /webhook
  -> проверка WEBHOOK_SECRET
  -> защита от дублей
  -> проверка временного окна отправки
  -> snapshot с Dahua через Digest auth
  -> upload image в MAX
  -> message с attachment в MAX
  -> диагностический лог
```

Фото на диск не сохраняется. Snapshot проходит через PHP в памяти.

## Текущая архитектура

```text
public/
  index.php

src/
  App.php
  Container/
    Container.php
  Camera/
    CameraRegistry.php
    CameraSource.php
    DahuaCamera.php
    SnapshotResult.php
  Config/
    AppConfig.php
  Event/
    DuplicateGuard.php
    TimeWindowFilter.php
  Logger/
    WebhookLogger.php
  Messenger/
    MaxMessenger.php
    CreateUploadResult.php
    UploadImageResult.php
    SendMessageResult.php
  Webhook/
    EventMessageFormatter.php
    WebhookRequest.php

docs/
  application-flow.md
  getChatIdMax.md
```

Главные роли:

- `public/index.php` — тонкая HTTP-точка входа.
- `src/App.php` — основной поток приложения.
- `src/Container/Container.php` — простой pseudo-DI-container.
- `WebhookRequest` — разбор входящего webhook-запроса.
- `AppConfig` — чтение настроек из env.
- `DuplicateGuard` — защита от дублей.
- `TimeWindowFilter` — временной фильтр отправки уведомлений.
- `CameraRegistry` — выбор настроек камеры по `source`.
- `CameraSource` — настройки одного источника: имя, label, snapshot URL, доступы Dahua, список MAX chat_id.
- `DahuaCamera` — получение snapshot.
- `MaxMessenger` — работа с MAX API.
- `EventMessageFormatter` — короткое клиентское сообщение для MAX.
- `WebhookLogger` — запись диагностического лога.

Сообщение для клиента в MAX должно быть коротким: событие, место, время. Технические детали `event`, `source`, `rule` оставлять в логах, а не перегружать ими сообщение.

## Важные настройки

Настоящие секреты лежат в `.env`. Не выводи их в ответе пользователю и не коммить.

Шаблон настроек:

```text
.env.example
```

Основные переменные:

```env
MAX_BOT_TOKEN=...

WEBHOOK_SECRET=...
DUPLICATE_TTL_SECONDS=5
NOTIFY_ALLOWED_FROM=
NOTIFY_ALLOWED_TO=
```

Для нескольких камер:

```env
CAMERA_SOURCES=gate,yard

CAMERA_GATE_LABEL=Ворота
CAMERA_GATE_URL=http://camera-ip/cgi-bin/snapshot.cgi?channel=1
CAMERA_GATE_USER=...
CAMERA_GATE_PASSWORD=...
CAMERA_GATE_MAX_CHAT_ID=
CAMERA_GATE_MAX_CHAT_IDS=
CAMERA_GATE_ALLOWED_RULES=
```

`CAMERA_<SOURCE>_MAX_CHAT_IDS` позволяет отправлять события конкретной камеры нескольким клиентам через запятую. Если значение пустое, используется одиночный `CAMERA_<SOURCE>_MAX_CHAT_ID`.

Глобального `MAX_CHAT_ID` fallback больше нет. В production каждая камера должна явно указывать своих получателей, чтобы событие не ушло не тому клиенту.

`CAMERA_<SOURCE>_ALLOWED_RULES` фильтрует события по `rule`. Если пусто, разрешены все правила. Пример только для транспорта:

```env
CAMERA_GATE_ALLOWED_RULES=vehicle_detection
```

После изменения переменных окружения для PHP нужно пересоздать контейнер:

```bash
docker compose up -d php
```

## Dahua webhook URL

Камера должна вызывать URL с реальным `WEBHOOK_SECRET`, а не с `...`.

Пример:

```text
/webhook?secret=WEBHOOK_SECRET_VALUE&event=ivs&source=dahua&rule=line_crossing
```

Если камера просит полный URL:

```text
http://10.10.0.141/webhook?secret=WEBHOOK_SECRET_VALUE&event=ivs&source=dahua&rule=line_crossing
```

Важно:

- HTTPS на первом этапе выключен.
- Порт используется `80`.
- Если включить HTTPS, нужен порт `443` и корректная TLS-настройка Nginx.
- В Dahua часто есть отдельные поля: сервер IP/порт и команда/путь. `secret`, `event`, `source`, `rule` должны быть именно в команде/пути webhook.
- В старых настройках камеры может оставаться `/webhook_receiver.php`; такие запросы дают `404` и не относятся к текущему рабочему endpoint.

## Source и несколько камер

Проект подготовлен к нескольким источникам через query-параметр:

```text
source
```

Примеры:

```text
/webhook?secret=...&event=ivs&source=gate&rule=line_crossing
/webhook?secret=...&event=ivs&source=yard&rule=intrusion
```

Для совместимости поддерживается старый параметр:

```text
camera
```

Логика определения source:

```text
source = query['source'] ?? query['camera'] ?? 'default'
```

Duplicate key:

```text
event:source:rule
```

`CameraRegistry` выбирает snapshot URL/логин/пароль и список MAX chat_id по `source`.

У каждой камеры может быть свой получатель в MAX:

```env
CAMERA_GATE_MAX_CHAT_ID=111111
CAMERA_GATE_MAX_CHAT_IDS=111111,333333
CAMERA_YARD_MAX_CHAT_ID=222222
CAMERA_YARD_MAX_CHAT_IDS=222222,444444
```

Если `CAMERA_<SOURCE>_MAX_CHAT_IDS` пустой, используется одиночный `CAMERA_<SOURCE>_MAX_CHAT_ID`. Если получатели камеры не заданы, камера не попадает в `CameraRegistry`.

`chat_id` клиента берется через MAX updates после того, как клиент написал боту. Подробная инструкция:

```text
docs/getChatIdMax.md
```

## Безопасность

Webhook защищен `WEBHOOK_SECRET`.

Если secret неверный:

```text
403 Forbidden
```

Камера и MAX при этом не вызываются.

Логи не должны содержать секреты:

- `secret` в query редактируется;
- `secret` в URI редактируется;
- `Authorization`, `Cookie`, `Set-Cookie` редактируются;
- raw body целиком не пишется, только `raw_body_length`;
- полный ответ MAX не пишется, только безопасные поля.

Если пользователь показывает секреты в чате или они были в коде, мягко напомни, что после стабилизации стоит перевыпустить токены/пароли.

## Дубли

`DuplicateGuard` хранит состояние в:

```text
storage/logs/duplicate-events.json
```

Если одно и то же событие приходит повторно в течение `DUPLICATE_TTL_SECONDS`, приложение возвращает:

```text
OK duplicate skipped
```

Snapshot и MAX при дубле не вызываются.

## Временной фильтр

`TimeWindowFilter` позволяет отправлять события только в заданный промежуток времени:

```env
NOTIFY_ALLOWED_FROM=08:00
NOTIFY_ALLOWED_TO=23:00
```

Если обе переменные пустые, фильтр выключен.

Время считается в timezone приложения `Europe/Moscow`.

Если событие пришло вне окна, приложение возвращает:

```text
OK time window skipped
```

Snapshot и MAX при этом не вызываются.

Окно может пересекать полночь:

```env
NOTIFY_ALLOWED_FROM=22:00
NOTIFY_ALLOWED_TO=07:00
```

## Логи

Основной лог:

```text
storage/logs/webhook.log
```

Полезные команды:

```bash
tail -n 120 storage/logs/webhook.log
tail -f storage/logs/webhook.log
```

Если сообщения в MAX не приходят, сначала смотри:

1. `webhook.log`
2. `docker compose logs --tail=100 nginx`

Типовые причины:

- `Invalid webhook secret` — камера отправляет неверный `secret`.
- `403` в Nginx — почти всегда неверный/отсутствующий `secret`.
- `404 /webhook_receiver.php` — старая настройка Dahua.
- `is_duplicate: true` — событие отфильтровано как дубль.
- `time_window.is_allowed: false` — событие пришло вне разрешенного времени.
- `rule_filter.is_allowed: false` — событие отфильтровано по `CAMERA_<SOURCE>_ALLOWED_RULES`.
- `camera_source.is_unknown: true` — `source` не найден в `CAMERA_SOURCES`, событие пропущено.
- `snapshot.http_code != 200` — проблема получения snapshot с камеры.
- `max_image_upload.has_token: false` — проблема upload изображения в MAX.
- `max.text_http_code != 200` — проблема отправки сообщения в MAX.

## Проверки после изменений

Минимальные проверки:

```bash
find src public -name '*.php' -print -exec php -l {} \;
```

Проверить autoload класса:

```bash
php -r 'require "vendor/autoload.php"; var_export(class_exists("App\\App")); echo PHP_EOL;'
```

Health-check:

```bash
curl -i http://10.10.0.141/health
```

Webhook вручную:

```bash
curl -i "http://10.10.0.141/webhook?secret=WEBHOOK_SECRET_VALUE&event=ivs&source=dahua&rule=line_crossing"
```

Если запросы из sandbox не видят host-порт, можно проверять через контейнер:

```bash
docker compose exec -T nginx wget -qO- "http://127.0.0.1/health"
```

Для Docker-команд может потребоваться escalation.

## Документация

README:

```text
README.md
```

Подробный поток приложения:

```text
docs/application-flow.md
```

Получение `chat_id` клиента в MAX:

```text
docs/getChatIdMax.md
```

Если меняется архитектура или URL-параметры, обновляй оба документа.

## Git и файлы

`.env`, логи и снимки должны быть исключены из git.

Сейчас есть:

```text
.gitignore
.env.example
storage/logs/.gitkeep
storage/snapshots/.gitkeep
```

Не удаляй пользовательские изменения без явного запроса.
