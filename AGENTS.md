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

Камеры, клиенты MAX и связи камер с клиентами хранятся в MySQL и управляются через `/profile`.

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
  assets/
    vendor/bootstrap/

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
  Database/
    Database.php
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
  Profile/
    ProfileController.php
    ProfileRepository.php
    ProfileSchema.php

docs/
  application-flow.md
  getChatIdMax.md
  production-deploy.md

resources/
  views/profile/
    index.php
    login.php
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
- `CameraSource` — настройки одного источника из MySQL: имя, label, snapshot URL, доступы Dahua, список MAX chat_id.
- `DahuaCamera` — получение snapshot.
- `MaxMessenger` — работа с MAX API.
- `EventMessageFormatter` — короткое клиентское сообщение для MAX.
- `WebhookLogger` — запись диагностического лога.
- `ProfileController` — кабинет `/profile` на Bootstrap 5.3.8.
- `ProfileRepository` — работа с таблицами `clients`, `cameras`, `camera_clients`.

Представления `/profile` лежат в `resources/views/profile`. Bootstrap подключается локально из `public/assets/vendor/bootstrap`, CDN в production не используется. Локальная логика формы профиля лежит в `public/assets/profile/profile.js`: она автоматически формирует скрытый `source` из названия камеры, snapshot URL из IP/host + канала и помогает копировать готовую webhook-команду для Dahua. Серверная подстраховка этой логики находится в `ProfileController`.

Сообщение для клиента в MAX должно быть коротким: событие, место, время. Технические детали `event`, `source`, `rule` оставлять в логах, а не перегружать ими сообщение.

## Важные настройки

Настоящие секреты лежат в `.env`. Не выводи их в ответе пользователю и не коммить.

Шаблон настроек:

```text
.env.example
```

Основные переменные:

```env
MYSQL_DATABASE=app_db
MYSQL_USER=app_user
MYSQL_PASSWORD=...

PROFILE_USERNAME=admin
PROFILE_PASSWORD_HASH='...'

DUPLICATE_TTL_SECONDS=5
NOTIFY_ALLOWED_FROM=
NOTIFY_ALLOWED_TO=
```

`MAX_BOT_TOKEN` и `WEBHOOK_SECRET` больше не задаются через `.env`. Они сохраняются в MySQL через `/profile` в блоке “Настройки сервиса”.

Камеры и получатели тоже не задаются через `.env`. Их нужно добавлять через `/profile`.

`PROFILE_PASSWORD_HASH` должен быть результатом `password_hash()`. В `.env` bcrypt-хеш берется в одинарные кавычки, потому что содержит `$`.

После изменения переменных окружения для PHP нужно пересоздать контейнер:

```bash
docker compose up -d php
```

## Dahua webhook URL

Камера должна вызывать URL с реальным `WEBHOOK_SECRET`, а не с `...`.

Основной короткий формат для Dahua:

```text
/w?s=WEBHOOK_SECRET_VALUE&e=ivs&c=dahua&r=line_crossing
```

Если камера просит полный URL:

```text
http://10.10.0.141/w?s=WEBHOOK_SECRET_VALUE&e=ivs&c=dahua&r=line_crossing
```

Старый длинный формат `/webhook?secret=...&event=...&source=...&rule=...` тоже поддерживается для совместимости.

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
/w?s=...&e=ivs&c=gate&r=line_crossing
/w?s=...&e=ivs&c=yard&r=intrusion
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

`CameraRegistry` выбирает snapshot URL/логин/пароль и список MAX chat_id по `source` из MySQL.

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
- `rule_filter.is_allowed: false` — событие отфильтровано по `allowed_rules` камеры из MySQL.
- `camera_source.is_unknown: true` — `source` не найден в MySQL, событие пропущено.
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
