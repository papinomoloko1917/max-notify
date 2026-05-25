# Production-развертывание Max Notify без Docker

Этот документ описывает развертывание Max Notify на сервере без Docker: системные Nginx, PHP-FPM, MySQL и доступ только из локальной сети.

Сценарий рассчитан на сервер, где уже работают другие сайты на `80` и `443`. Поэтому Max Notify не занимает эти порты напрямую: для него используется отдельный локальный порт, например `8081`, или отдельный server block внутри существующего Nginx.

## 1. Схема

```text
локальная сеть
  -> http://SERVER_LAN_IP:8081
  -> системный Nginx
  -> PHP-FPM
  -> MySQL
```

Примеры URL:

```text
http://SERVER_LAN_IP:8081/profile
http://SERVER_LAN_IP:8081/webhook?secret=...&event=ivs&source=home&rule=line_crossing
```

Если хочешь использовать доменное имя в локальной сети, можно настроить локальный DNS:

```text
http://notify.lan/profile
```

## 2. Установить системные пакеты

Пример для Ubuntu/Debian:

```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.2-fpm php8.2-cli php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml unzip git
```

Проверить:

```bash
php -v
php -m | grep -E 'pdo_mysql|curl|mbstring'
nginx -v
mysql --version
```

Composer:

```bash
composer --version
```

Если Composer не установлен, установи его по официальной инструкции Composer.

## 3. Разместить проект

Пример пути:

```bash
sudo mkdir -p /var/www/max-notify
sudo chown -R "$USER":www-data /var/www/max-notify
git clone <repo-url> /var/www/max-notify
cd /var/www/max-notify
```

Установить зависимости:

```bash
composer install --no-dev --optimize-autoloader
```

Права:

```bash
sudo chown -R www-data:www-data /var/www/max-notify/storage
sudo chmod -R 775 /var/www/max-notify/storage
```

## 4. Настроить MySQL

Зайти в MySQL:

```bash
sudo mysql
```

Создать базу и пользователя:

```sql
CREATE DATABASE max_notify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'max_notify'@'localhost' IDENTIFIED BY 'strong_db_password';
GRANT ALL PRIVILEGES ON max_notify.* TO 'max_notify'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

В MySQL будут храниться:

```text
clients
cameras
camera_clients
```

Таблицы создаются автоматически при первом открытии `/profile`.

## 5. Настроить `.env`

```bash
cd /var/www/max-notify
cp .env.example .env
```

Минимальный production-набор:

```env
MYSQL_DATABASE=max_notify
MYSQL_USER=max_notify
MYSQL_PASSWORD=strong_db_password

PROFILE_USERNAME=admin
PROFILE_PASSWORD_HASH='...'

DUPLICATE_TTL_SECONDS=5
NOTIFY_ALLOWED_FROM=
NOTIFY_ALLOWED_TO=
```

`MAX_BOT_TOKEN` и `WEBHOOK_SECRET` задаются после входа в `/profile` в блоке “Настройки сервиса”.

Если MySQL находится на этом же сервере, используй:

```env
MYSQL_HOST=127.0.0.1
```

Сгенерировать пароль для `/profile`:

```bash
php -r 'echo password_hash("your-strong-password", PASSWORD_DEFAULT), PHP_EOL;'
```

Важно: bcrypt-хеш содержит `$`, поэтому в `.env` бери его в одинарные кавычки:

```env
PROFILE_PASSWORD_HASH='$2y$...'
```

Сгенерировать webhook secret:

```bash
openssl rand -hex 32
```

## 6. Настроить Nginx на локальный порт

Создать конфиг:

```bash
sudo nano /etc/nginx/sites-available/max-notify.conf
```

Вариант с отдельным портом `8081` только для локальной сети:

```nginx
server {
    listen 8081;
    server_name _;

    root /var/www/max-notify/public;
    index index.php;

    access_log /var/log/nginx/max-notify.access.log;
    error_log /var/log/nginx/max-notify.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Включить сайт:

```bash
sudo ln -s /etc/nginx/sites-available/max-notify.conf /etc/nginx/sites-enabled/max-notify.conf
sudo nginx -t
sudo systemctl reload nginx
```

Если у тебя PHP-FPM другой версии, проверь socket:

```bash
ls /run/php/
```

Например для PHP 8.3 будет:

```nginx
fastcgi_pass unix:/run/php/php8.3-fpm.sock;
```

## 7. Ограничить доступ локальной сетью

Если сервер доступен из интернета, порт `8081` лучше закрыть firewall.

Пример для UFW, если локальная сеть `10.10.0.0/24`:

```bash
sudo ufw allow from 10.10.0.0/24 to any port 8081 proto tcp
sudo ufw deny 8081/tcp
```

Если UFW не используется, настрой аналогичные правила на уровне firewall сервера или роутера.

Также можно ограничить доступ в Nginx:

```nginx
allow 10.10.0.0/24;
deny all;
```

Но будь аккуратен: если камера и админский компьютер находятся в разных подсетях, добавь обе.

## 8. Проверить приложение

Health-check:

```bash
curl -i http://SERVER_LAN_IP:8081/health
```

Ожидаемо:

```text
HTTP/1.1 200 OK
OK
```

Открыть кабинет:

```text
http://SERVER_LAN_IP:8081/profile
```

Войти под `PROFILE_USERNAME` и паролем, для которого был создан `PROFILE_PASSWORD_HASH`.

## 9. Добавить клиентов и камеры

В `/profile`:

1. Добавь клиента MAX.
2. Укажи его `chat_id`.
3. Добавь камеру.
4. Укажи:
   - `source`, например `home`;
   - название камеры;
   - snapshot URL Dahua/NVR;
   - логин и пароль Dahua;
   - разрешенные `rule`, если нужна фильтрация;
   - одного или нескольких клиентов.

`source` должен совпадать с webhook URL камеры.

В кабинете также можно редактировать и удалять клиентов и камеры. Разрешенные события выбираются чекбоксами. Связи в `camera_clients` удаляются автоматически.

## 10. Настроить Dahua/NVR webhook

Пример:

```text
http://SERVER_LAN_IP:8081/webhook?secret=WEBHOOK_SECRET_VALUE&event=ivs&source=home&rule=line_crossing
```

SMD человек:

```text
http://SERVER_LAN_IP:8081/webhook?secret=WEBHOOK_SECRET_VALUE&event=smd&source=home&rule=human_detection
```

SMD транспорт:

```text
http://SERVER_LAN_IP:8081/webhook?secret=WEBHOOK_SECRET_VALUE&event=smd&source=home&rule=vehicle_detection
```

Важно:

- `secret` должен совпадать с `WEBHOOK_SECRET`.
- `source` должен существовать в MySQL.
- Если `source` неизвестен, будет `OK unknown source skipped`.
- Если `rule` не разрешен для камеры, будет `OK rule skipped`.

## 11. Получить chat_id клиента MAX

Инструкция:

```text
docs/getChatIdMax.md
```

Коротко:

```bash
curl -s "https://platform-api.max.ru/updates?timeout=10&limit=10&types=message_created" \
  -H "Authorization: MAX_BOT_TOKEN"
```

## 12. Логи

Лог приложения:

```bash
tail -n 120 /var/www/max-notify/storage/logs/webhook.log
```

Логи Nginx:

```bash
sudo tail -n 100 /var/log/nginx/max-notify.access.log
sudo tail -n 100 /var/log/nginx/max-notify.error.log
```

Логи PHP-FPM:

```bash
sudo journalctl -u php8.2-fpm -n 100 --no-pager
```

Типовые причины:

- `Invalid webhook secret` - неверный `secret`.
- `OK unknown source skipped` - камера с таким `source` не найдена в MySQL.
- `OK rule skipped` - событие отфильтровано по `allowed_rules`.
- `OK duplicate skipped` - дубль события.
- `snapshot.http_code != 200` - не удалось получить snapshot.
- `max_image_upload.has_token: false` - не удалось загрузить фото в MAX.
- `max.text_http_code != 200` - не удалось отправить сообщение в MAX.

## 13. Обновление

```bash
cd /var/www/max-notify
git pull
composer install --no-dev --optimize-autoloader
sudo systemctl reload php8.2-fpm
sudo systemctl reload nginx
```

Проверить:

```bash
curl -i http://SERVER_LAN_IP:8081/health
```

## 14. Backup

Важные данные:

- `.env`;
- MySQL база `max_notify`;
- при необходимости `storage/logs`.

Dump MySQL:

```bash
mysqldump -u max_notify -p max_notify > max-notify-backup.sql
```

Восстановление:

```bash
mysql -u max_notify -p max_notify < max-notify-backup.sql
```

## 15. Security checklist

- `.env` не коммитится.
- `Webhook secret` в `/profile` длинный и случайный.
- `/profile` доступен только из локальной сети.
- Порт `8081` закрыт от интернета.
- `PROFILE_PASSWORD_HASH` создан от сильного пароля.
- У пользователя Dahua минимальные права: snapshot.
- Bootstrap подключен локально из `public/assets`, CDN не нужен.
- После утечки токена MAX, пароля Dahua или `.env` значения перевыпущены.
