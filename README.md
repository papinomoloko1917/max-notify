# Max Notify

Учебно-практический PHP-сервис для приема webhook-событий от камеры Dahua/NVR, получения snapshot и отправки фото в российский мессенджер MAX.

## Что делает сервис

1. Принимает HTTP webhook от Dahua.
2. Проверяет `WEBHOOK_SECRET`.
3. Отсекает дубли событий в течение `DUPLICATE_TTL_SECONDS`.
4. Получает snapshot с Dahua/NVR через Digest-авторизацию.
5. Загружает изображение в MAX.
6. Отправляет сообщение с фото в MAX.
7. Пишет диагностический лог в `storage/logs/webhook.log`.

Фото на сервере не сохраняется: изображение проходит через PHP в памяти и отправляется в MAX.

## Стек

- PHP 8.2
- Composer
- Nginx
- Docker Compose
- MySQL/phpMyAdmin в окружении проекта, но текущий webhook пока не использует БД
- Symfony VarDumper для разработки

## Структура

```text
public/
  index.php                 HTTP entrypoint

src/
  App.php                   основной поток приложения
  Container/
    Container.php           простой DI/pseudo-container
  Camera/
    DahuaCamera.php         получение snapshot
    SnapshotResult.php
  Config/
    AppConfig.php           чтение настроек из env
  Event/
    DuplicateGuard.php      защита от дублей
  Logger/
    WebhookLogger.php       запись диагностического лога
  Messenger/
    MaxMessenger.php        MAX API client
    CreateUploadResult.php
    UploadImageResult.php
    SendMessageResult.php
  Webhook/
    WebhookRequest.php      разбор входящего запроса
```

## Установка

Скопировать шаблон настроек:

```bash
cp .env.example .env
```

Установить зависимости:

```bash
composer install
```

Запустить контейнеры:

```bash
docker compose up -d
```

Если менялись переменные окружения для PHP, пересоздать PHP-контейнер:

```bash
docker compose up -d php
```

## Настройки `.env`

Основные переменные:

```env
MAX_BOT_TOKEN=change_me
MAX_CHAT_ID=change_me

DAHUA_CAMERA_URL=http://camera-ip/cgi-bin/snapshot.cgi?channel=1
DAHUA_CAMERA_USER=change_me
DAHUA_CAMERA_PASSWORD=change_me

WEBHOOK_SECRET=change_me
DUPLICATE_TTL_SECONDS=5
```

`WEBHOOK_SECRET` должен быть длинной случайной строкой. Его нужно добавить в URL webhook в камере.

## URL для Dahua

Для первой камеры:

```text
http://10.10.0.141/webhook?secret=WEBHOOK_SECRET&event=ivs&source=gate&rule=line_crossing
```

Где:

- `10.10.0.141` - IP сервера с Docker/Nginx
- `secret` - значение `WEBHOOK_SECRET` из `.env`
- `event` - тип события, например `ivs`
- `source` - понятное имя источника, например `gate`, `yard`, `parking`
- `rule` - правило, например `line_crossing`

Для совместимости также поддерживается старый параметр `camera`:

```text
/webhook?secret=...&event=ivs&camera=dahua&rule=line_crossing
```

Но для будущих нескольких камер лучше использовать `source`.

## HTTP/HTTPS

Для первого и простого сценария используется HTTP:

```text
http://10.10.0.141:80
```

Если в интерфейсе Dahua включить HTTPS, нужен порт `443` и корректная TLS-настройка Nginx. HTTPS на порту `80` приведет к сетевым ошибкам.

## Проверка

Health-check без обращения к камере/MAX:

```bash
curl -i http://10.10.0.141/health
```

Ожидаемо:

```text
HTTP/1.1 200 OK
OK
```

Webhook с секретом:

```bash
curl -i "http://10.10.0.141/webhook?secret=WEBHOOK_SECRET&event=ivs&source=gate&rule=line_crossing"
```

Webhook без секрета должен вернуть:

```text
HTTP/1.1 403 Forbidden
Forbidden
```

Лог:

```bash
tail -n 120 storage/logs/webhook.log
```

## Защита от дублей

Ключ дубля строится так:

```text
event:source:rule
```

Например:

```text
ivs:gate:line_crossing
```

Если такое же событие приходит повторно в течение `DUPLICATE_TTL_SECONDS`, сервис пишет событие в лог, но не получает snapshot и не отправляет фото в MAX.

Состояние дублей хранится в:

```text
storage/logs/duplicate-events.json
```

## Логи

Основной лог:

```text
storage/logs/webhook.log
```

Секреты в логах редактируются:

- `secret` в query и URI заменяется на `[redacted]`
- `Authorization`, `Cookie`, `Set-Cookie` в headers заменяются на `[redacted]`
- raw body целиком не пишется, пишется только `raw_body_length`
- полный ответ MAX не пишется, сохраняются только `http_code`, `message_id`, `has_attachment`

## MAX

Сервис использует официальный поток отправки изображения:

1. Создать upload через MAX API.
2. Загрузить JPEG.
3. Отправить сообщение с attachment token.

Перед настройкой сервиса нужно получить `MAX_CHAT_ID`. Для этого пользователь или чат должен написать боту, после чего `chat_id` можно получить через updates API MAX.

## Несколько камер или NVR

Текущая версия уже готовит модель под несколько источников через параметр `source`.

Примеры:

```text
/webhook?secret=...&event=ivs&source=gate&rule=line_crossing
/webhook?secret=...&event=ivs&source=yard&rule=intrusion
/webhook?secret=...&event=ivs&source=parking&rule=line_crossing
```

Для NVR в будущем можно добавить `channel`:

```text
/webhook?secret=...&event=ivs&source=nvr&channel=1&rule=line_crossing
```

Следующий архитектурный шаг для нескольких камер - добавить `CameraRegistry`, который будет выбирать snapshot URL по `source` или `channel`.

## Production notes

- Не коммитить `.env`.
- После публикации или передачи проекта сменить токены и пароли, если они где-то светились.
- Использовать отдельного пользователя Dahua только для snapshot.
- Ограничить доступ к webhook на уровне сети/firewall, если возможно.
- Для публичного доступа настроить HTTPS на `443`.
- Не хранить фото на сервере без необходимости.
