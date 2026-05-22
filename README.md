![Max Notify](/public/img/icon.png)

# Max Notify

PHP-сервис для приема webhook-событий от камер Dahua/NVR, получения snapshot и отправки уведомлений с фото в российский мессенджер MAX.

## Что делает сервис

1. Принимает HTTP webhook от Dahua.
2. Проверяет `WEBHOOK_SECRET`.
3. Отсекает дубли событий в течение `DUPLICATE_TTL_SECONDS`.
4. Проверяет временное окно отправки, если оно настроено.
5. Получает snapshot с Dahua/NVR через Digest-авторизацию.
6. Загружает изображение в MAX.
7. Отправляет короткое клиентское сообщение с фото в MAX.
8. Пишет диагностический лог в `storage/logs/webhook.log`.

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
    CameraRegistry.php      выбор камеры по source
    CameraSource.php        настройки одного источника
    DahuaCamera.php         получение snapshot
    SnapshotResult.php
  Config/
    AppConfig.php           чтение настроек из env
  Event/
    DuplicateGuard.php      защита от дублей
    TimeWindowFilter.php    временной фильтр отправки
  Logger/
    WebhookLogger.php       запись диагностического лога
  Messenger/
    MaxMessenger.php        MAX API client
    CreateUploadResult.php
    UploadImageResult.php
    SendMessageResult.php
  Webhook/
    EventMessageFormatter.php русские тексты событий для MAX
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

WEBHOOK_SECRET=change_me
DUPLICATE_TTL_SECONDS=5
NOTIFY_ALLOWED_FROM=
NOTIFY_ALLOWED_TO=
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

Для нескольких камер лучше использовать `source`.

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

## Временной фильтр

Можно отправлять события в MAX только в заданный промежуток времени:

```env
NOTIFY_ALLOWED_FROM=08:00
NOTIFY_ALLOWED_TO=23:00
```

Время считается по часовому поясу сервера приложения: `Europe/Moscow`.

Если обе переменные пустые, фильтр выключен и уведомления отправляются в любое время. Если событие пришло вне разрешенного окна, сервис пишет его в лог, но не получает snapshot и не отправляет сообщение в MAX.

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

Сообщение в MAX специально короткое, без технических деталей:

```text
🚨 Пересечение линии
📍 Ворота
🕒 22.05 08:43 МСК
```

Технические значения `event`, `source`, `rule` остаются в диагностическом логе.

Перед настройкой сервиса нужно получить `chat_id`. Для этого пользователь или чат должен написать боту, после чего `chat_id` можно получить через updates API MAX.

Подробная инструкция:

```text
docs/getChatIdMax.md
```

## Несколько камер или NVR

Текущая версия уже готовит модель под несколько источников через параметр `source`.

Примеры:

```text
/webhook?secret=...&event=ivs&source=gate&rule=line_crossing
/webhook?secret=...&event=ivs&source=yard&rule=intrusion
/webhook?secret=...&event=ivs&source=parking&rule=line_crossing
```

Настройки нескольких камер задаются через `.env`:

```env
CAMERA_SOURCES=gate,yard

CAMERA_GATE_LABEL=Ворота
CAMERA_GATE_URL=http://10.10.0.181/cgi-bin/snapshot.cgi?channel=1
CAMERA_GATE_USER=server
CAMERA_GATE_PASSWORD=change_me
CAMERA_GATE_MAX_CHAT_IDS=111111,333333
CAMERA_GATE_ALLOWED_RULES=vehicle_detection

CAMERA_YARD_LABEL=Двор
CAMERA_YARD_URL=http://10.10.0.182/cgi-bin/snapshot.cgi?channel=1
CAMERA_YARD_USER=server
CAMERA_YARD_PASSWORD=change_me
CAMERA_YARD_MAX_CHAT_IDS=222222,444444
CAMERA_YARD_ALLOWED_RULES=human_detection,vehicle_detection
```

`source` в URL лучше оставлять латиницей: `gate`, `yard`, `parking`. Русское название для сообщения в MAX задается отдельной переменной `CAMERA_<SOURCE>_LABEL`.

`CAMERA_<SOURCE>_MAX_CHAT_IDS` позволяет отправлять события конкретной камеры сразу в несколько чатов MAX. Если она пустая, используется одиночный `CAMERA_<SOURCE>_MAX_CHAT_ID`.

В production у каждой камеры должен быть задан свой `CAMERA_<SOURCE>_MAX_CHAT_ID` или `CAMERA_<SOURCE>_MAX_CHAT_IDS`. Общего fallback-получателя нет специально: это защищает от отправки событий не тому клиенту.

`CAMERA_<SOURCE>_ALLOWED_RULES` позволяет отправлять только нужные типы событий. Если переменная пустая, разрешены все правила. Например, только транспорт:

```env
CAMERA_GATE_ALLOWED_RULES=vehicle_detection
```

Если придет другое правило, сервис запишет событие в лог и вернет `OK rule skipped`, но не будет получать snapshot и отправлять MAX.

Если `source` не найден в `CAMERA_SOURCES`, событие пропускается с ответом `OK unknown source skipped`. Snapshot и MAX при этом не вызываются.

После изменения `.env` нужно пересоздать PHP-контейнер:

```bash
docker compose up -d php
```

Для NVR можно указать разные source с разными channel:

```text
/webhook?secret=...&event=ivs&source=nvr_ch1&rule=line_crossing
/webhook?secret=...&event=ivs&source=nvr_ch2&rule=line_crossing
```

## Production notes

- Не коммитить `.env`.
- После публикации или передачи проекта сменить токены и пароли, если они где-то светились.
- Использовать отдельного пользователя Dahua только для snapshot.
- Ограничить доступ к webhook на уровне сети/firewall, если возможно.
- Для публичного доступа настроить HTTPS на `443`.
- Не хранить фото на сервере без необходимости.
