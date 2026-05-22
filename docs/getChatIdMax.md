# Как получить chat_id клиента в MAX

`chat_id` нужен, чтобы отправлять уведомления конкретному клиенту или в конкретный чат.

## 1. Клиент должен написать боту

Клиент открывает твоего бота в MAX и отправляет любое сообщение, например:

```text
/start
```

Важно: сообщение должно быть отправлено именно тому боту, токен которого указан в `.env`:

```env
MAX_BOT_TOKEN=...
```

## 2. Проверить, что токен правильный

```bash
curl -s "https://platform-api.max.ru/me" \
  -H "Authorization: ТВОЙ_MAX_BOT_TOKEN"
```

В ответе должна прийти информация о твоем боте.

## 3. Проверить, нет ли webhook-подписки MAX

```bash
curl -s "https://platform-api.max.ru/subscriptions" \
  -H "Authorization: ТВОЙ_MAX_BOT_TOKEN"
```

Если у бота включена webhook-подписка MAX, то получать события через `/updates` может не получиться. Для тестов проще использовать `/updates`, а для production лучше отдельный HTTPS webhook для событий MAX.

## 4. Попросить клиента написать еще одно сообщение

После этого сразу выполнить:

```bash
curl -s "https://platform-api.max.ru/updates?timeout=10&limit=10&types=message_created" \
  -H "Authorization: ТВОЙ_MAX_BOT_TOKEN"
```

Если `jq` не установлен, можно красиво вывести через PHP:

```bash
curl -s "https://platform-api.max.ru/updates?timeout=10&limit=10&types=message_created" \
  -H "Authorization: ТВОЙ_MAX_BOT_TOKEN" \
  | php -r '$data = json_decode(stream_get_contents(STDIN), true); print_r($data);'
```

## 5. Где искать chat_id

В ответе ищи поле:

```text
chat_id
```

В разных типах update оно может лежать в разных местах, например:

```text
updates.*.chat_id
updates.*.message.recipient.chat_id
updates.*.message.chat.chat_id
```

Можно попробовать вытащить возможные `chat_id` так:

```bash
curl -s "https://platform-api.max.ru/updates?timeout=10&limit=10&types=message_created" \
  -H "Authorization: ТВОЙ_MAX_BOT_TOKEN" \
  | php -r '$data = json_decode(stream_get_contents(STDIN), true); foreach (($data["updates"] ?? []) as $update) { $chatId = $update["chat_id"] ?? $update["message"]["recipient"]["chat_id"] ?? $update["message"]["chat"]["chat_id"] ?? null; if ($chatId !== null) { echo $chatId . PHP_EOL; } }'
```

## 6. Что делать с chat_id

Если это один получатель для конкретной камеры:

```env
CAMERA_GATE_MAX_CHAT_ID=123456789
```

Если получателей несколько:

```env
CAMERA_GATE_MAX_CHAT_IDS=123456789,987654321
```

Глобального `MAX_CHAT_ID` в production-конфигурации нет специально: получатели должны быть привязаны к конкретной камере.

После изменения `.env` нужно пересоздать PHP-контейнер:

```bash
docker compose up -d php
```

## Частые причины, почему сообщение клиента не видно

1. Клиент написал не тому боту.
2. Используется токен другого бота.
3. Сообщение было отправлено до запроса, а MAX вернул только последнее событие.
4. У бота включена webhook-подписка MAX, и Long Polling через `/updates` не используется.
5. События хранятся ограниченное время, поэтому старые сообщения могут не вернуться.
6. В групповом чате бот должен быть участником, а для некоторых событий - администратором.
