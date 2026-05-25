# Production-развертывание Max Notify на Ubuntu Server без Docker

Документ описывает установку Max Notify на Ubuntu Server, где уже работают другие сайты через системный Nginx.

Текущие вводные сервера:

```text
Ubuntu Server
Nginx 1.24.0
MySQL 8.0.45
PHP с pdo_mysql
PHP-FPM socket: /run/php/php8.3-fpm.sock
LAN IP сервера: 10.10.0.101
npm 10.8.2
phpMyAdmin не установлен
```

Проект не требует Docker и не требует phpMyAdmin. `npm` для production тоже не нужен: Bootstrap уже лежит локально в `public/assets`.

## 1. Как не мешать трем существующим сайтам

Если на сервере уже заняты `80` и `443`, не нужно поднимать Max Notify как четвертый публичный сайт на этих же портах.

Самый простой и безопасный вариант для локальной сети/VPN:

```text
камера Dahua/NVR
  -> VPN / LAN
  -> http://SERVER_LAN_IP:8081/w?s=...&e=ivs&c=ofis&r=line_crossing
  -> системный Nginx
  -> PHP-FPM
  -> MySQL
```

То есть:

- существующие сайты остаются на `80/443`;
- Max Notify слушает отдельный порт, например `8081`;
- порт `8081` открывается только для LAN/VPN;
- доступ из интернета к Max Notify не нужен.

## 2. Проверить системные зависимости

На сервере уже проверено:

```bash
php -m | grep pdo_mysql
mysql --version
nginx -v
npm -v
```

Дополнительно проверь PHP-FPM и нужные PHP-модули:

```bash
php -v
php -m | grep -E 'curl|pdo_mysql|mbstring'
systemctl status php*-fpm --no-pager
ls /run/php/
```

Для проекта нужны:

```text
pdo_mysql
curl
mbstring
```

Если чего-то не хватает:

```bash
sudo apt update
sudo apt install -y php-curl php-mbstring php-mysql unzip git
```

Composer:

```bash
composer --version
```

Если Composer не установлен:

```bash
sudo apt install -y composer
```

## 3. Создать директорию проекта

Рекомендуемый путь:

```bash
sudo mkdir -p /var/www/max-notify
sudo chown -R "$USER":www-data /var/www/max-notify
```

Загрузить проект:

```bash
git clone <repo-url> /var/www/max-notify
cd /var/www/max-notify
```

Если проект копируется вручную, важно перенести:

```text
composer.json
composer.lock
public/
resources/
src/
storage/
.env.example
```

Docker-файлы на production-сервере не нужны.

## 4. Установить PHP-зависимости

```bash
cd /var/www/max-notify
composer install --no-dev --optimize-autoloader
```

Проверить autoload:

```bash
php -r 'require "vendor/autoload.php"; var_export(class_exists("App\\App")); echo PHP_EOL;'
```

Ожидаемо:

```text
true
```

## 5. Настроить права

Nginx/PHP-FPM обычно работают от пользователя `www-data`.

```bash
sudo chown -R "$USER":www-data /home/ninjamax1917/sites/max-notify
sudo chown -R www-data:www-data /home/ninjamax1917/sites/max-notify/storage
sudo chmod -R 775 /home/ninjamax1917/sites/max-notify/storage
```

Проверить:

```bash
ls -la /home/ninjamax1917/sites/max-notify/storage
```

## 6. Создать MySQL-базу

Зайти в MySQL:

```bash
sudo mysql
```

Создать базу и пользователя:

```sql
CREATE DATABASE max_notify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'max_notify'@'localhost' IDENTIFIED BY 'password';
GRANT ALL PRIVILEGES ON max_notify.* TO 'max_notify'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Если приложение будет подключаться к `127.0.0.1`, лучше создать пользователя и для этого host:

```sql
CREATE USER 'max_notify'@'127.0.0.1' IDENTIFIED BY 'password';
GRANT ALL PRIVILEGES ON max_notify.* TO 'max_notify'@'127.0.0.1';
FLUSH PRIVILEGES;
```

На практике можно использовать `MYSQL_HOST=localhost` или `MYSQL_HOST=127.0.0.1`. Главное, чтобы пользователь MySQL совпадал с host.

## 7. Настроить `.env`

```bash
cd /sites/var/www/max-notify
cp .env.example .env
nano .env
```

Минимальный production-набор:

```env
MYSQL_HOST=localhost
MYSQL_DATABASE=max_notify
MYSQL_USER=max_notify
MYSQL_PASSWORD=strong_db_password

PROFILE_USERNAME=admin
PROFILE_PASSWORD_HASH='...'

DUPLICATE_TTL_SECONDS=5
NOTIFY_ALLOWED_FROM=
NOTIFY_ALLOWED_TO=
```

`MAX_BOT_TOKEN` и `WEBHOOK_SECRET` больше не нужно хранить в `.env`. Они задаются в `/profile` и сохраняются в MySQL.

Приложение само читает файл `.env` при входе через `public/index.php`; отдельный пакет `phpdotenv` для production не нужен.

Создать хеш пароля для входа в `/profile`:

```bash
php -r 'echo password_hash("your-strong-password", PASSWORD_DEFAULT), PHP_EOL;'
```

Важно: bcrypt-хеш содержит символы `$`, поэтому в `.env` его нужно брать в одинарные кавычки:

```env
PROFILE_PASSWORD_HASH='$2y$...'
```

## 8. Узнать версию PHP-FPM socket

Посмотреть доступные socket-файлы:

```bash
ls /run/php/
```

Примеры:

```text
php8.3-fpm.sock
php8.2-fpm.sock
```

В Nginx-конфиге ниже нужно указать реальный socket.

## 9. Создать Nginx-конфиг на отдельном порту

Создать файл:

```bash
sudo nano /etc/nginx/sites-available/max-notify.conf
```

Пример для порта `8081`:

```nginx
server {
    listen 8081;
    server_name _;

    root /sites/var/www/max-notify/public;
    index index.php;

    access_log /var/log/nginx/max-notify.access.log;
    error_log /var/log/nginx/max-notify.error.log;

    client_max_body_size 5m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Если у тебя socket другой, замени строку:

```nginx
fastcgi_pass unix:/run/php/php8.3-fpm.sock;
```

Например:

```nginx
fastcgi_pass unix:/run/php/php8.2-fpm.sock;
```

Включить конфиг:

```bash
sudo ln -s /etc/nginx/sites-available/max-notify.conf /etc/nginx/sites-enabled/max-notify.conf
sudo nginx -t
sudo systemctl reload nginx
```

Этот вариант не трогает конфиги трех существующих сайтов.

Для текущего сервера это правильный socket, потому что `ls /run/php/` показывает:

```text
php8.3-fpm.sock
php-fpm.sock
```

После включения конфига Max Notify будет доступен так:

```text
http://10.10.0.101:8081/health
http://10.10.0.101:8081/profile
```

А webhook для Dahua будет выглядеть так:

```text
http://10.10.0.101:8081/w?s=SECRET&e=ivs&c=ofis&r=line_crossing
```

## 10. Ограничить доступ только LAN/VPN

Если используется UFW, пример для VPN/LAN сети `10.10.0.0/24`:

```bash
sudo ufw allow from 10.10.0.0/24 to any port 8081 proto tcp
sudo ufw deny 8081/tcp
```

Если есть отдельная VPN-подсеть, например `10.8.0.0/24`, добавь ее:

```bash
sudo ufw allow from 10.8.0.0/24 to any port 8081 proto tcp
```

Также можно ограничить доступ в Nginx:

```nginx
allow 10.10.0.0/24;
allow 10.8.0.0/24;
deny all;
```

Эти строки можно добавить внутрь `server { ... }`. Но сначала убедись, что IP камеры и IP твоего компьютера действительно входят в разрешенные подсети.

## 11. Проверить запуск

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

Войти под:

```text
PROFILE_USERNAME
пароль, от которого создавался PROFILE_PASSWORD_HASH
```

При первом входе приложение автоматически создаст таблицы:

```text
profile_settings
clients
cameras
camera_clients
```

## 12. Первичная настройка в `/profile`

В кабинете:

1. Открой блок `Настройки сервиса`.
2. Вставь `MAX bot token`.
3. Нажми `Сгенерировать` рядом с `Webhook secret` или введи свой короткий secret.
4. Нажми `Сохранить настройки`.
5. Добавь клиента MAX и его `chat_id`.
6. Добавь камеру:
   - название;
   - IP/host камеры или NVR;
   - канал;
   - логин Dahua;
   - пароль Dahua;
   - нужные события;
   - клиентов, которым отправлять уведомления.

`source` формируется автоматически из названия камеры. Вручную его вводить не нужно.

## 13. Настроить Dahua/NVR webhook

В `/profile` у камеры будет готовая короткая команда вида:

```text
/w?s=SECRET&e=ivs&c=ofis&r=line_crossing
```

Если Dahua просит полный URL:

```text
http://SERVER_LAN_IP:8081/w?s=SECRET&e=ivs&c=ofis&r=line_crossing
```

Расшифровка коротких параметров:

```text
s = secret
e = event
c = camera/source
r = rule
```

Если в Dahua есть отдельные поля:

```text
IP/домен сервера: SERVER_LAN_IP
Порт: 8081
HTTPS: выключить, если TLS не настроен
Команда/путь: /w?s=SECRET&e=ivs&c=ofis&r=line_crossing
```

Важно:

- вставляй только одну команду под конкретное событие;
- не вставляй сразу несколько строк;
- старый длинный формат `/webhook?secret=...&event=...&source=...&rule=...` тоже поддерживается, но короткий формат лучше помещается в поле Dahua;
- если изменил `Webhook secret`, нужно заново скопировать команды в камеры.

## 14. Проверить webhook вручную

С сервера:

```bash
curl -i "http://127.0.0.1:8081/w?s=SECRET&e=ivs&c=ofis&r=line_crossing"
```

Из локальной сети/VPN:

```bash
curl -i "http://SERVER_LAN_IP:8081/w?s=SECRET&e=ivs&c=ofis&r=line_crossing"
```

Возможные ответы:

```text
OK
OK duplicate skipped
OK rule skipped
OK unknown source skipped
Forbidden
Missing profile settings
```

Если ответ `Forbidden`, проверь secret.

Если `OK unknown source skipped`, проверь, что `c=...` совпадает с камерой из `/profile`.

## 15. Получить chat_id клиента MAX

Подробная инструкция:

```text
docs/getChatIdMax.md
```

Короткий тест updates:

```bash
curl -s "https://platform-api.max.ru/updates?timeout=10&limit=10&types=message_created" \
  -H "Authorization: MAX_BOT_TOKEN"
```

`MAX_BOT_TOKEN` берется из кабинета `/profile`.

## 16. Логи

Лог приложения:

```bash
tail -n 120 /sites/var/www/max-notify/storage/logs/webhook.log
tail -f /sites/var/www/max-notify/storage/logs/webhook.log
```

Логи Nginx:

```bash
sudo tail -n 100 /var/log/nginx/max-notify.access.log
sudo tail -n 100 /var/log/nginx/max-notify.error.log
```

Логи PHP-FPM:

```bash
sudo journalctl -u php8.3-fpm -n 100 --no-pager
```

Если версия другая:

```bash
systemctl list-units 'php*-fpm.service'
```

Типовые причины проблем:

- `Forbidden` - неверный secret.
- `Missing profile settings` - в `/profile` не сохранены MAX token или webhook secret.
- `OK unknown source skipped` - камера с таким `c/source` не найдена в MySQL.
- `OK rule skipped` - событие отфильтровано по разрешенным событиям камеры.
- `OK duplicate skipped` - событие пришло повторно в течение `DUPLICATE_TTL_SECONDS`.
- `snapshot.http_code != 200` - не удалось получить snapshot с Dahua/NVR.
- `max_image_upload.has_token: false` - не удалось загрузить фото в MAX.
- `max.text_http_code != 200` - не удалось отправить сообщение в MAX.

## 17. Обновление проекта

```bash
cd /sites/var/www/max-notify
git pull
composer install --no-dev --optimize-autoloader
find src public resources -name '*.php' -print -exec php -l {} \;
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
```

Если PHP-FPM другой версии, замени `php8.3-fpm` на свою.

Проверить:

```bash
curl -i http://127.0.0.1:8081/health
```

## 18. Backup

Важные данные:

- `.env`;
- MySQL база `max_notify`;
- при необходимости `storage/logs`.

Dump:

```bash
mysqldump -u max_notify -p max_notify > max-notify-backup.sql
```

Восстановление:

```bash
mysql -u max_notify -p max_notify < max-notify-backup.sql
```

## 19. Security checklist

- `.env` не коммитится.
- `/profile` доступен только из LAN/VPN.
- Порт `8081` закрыт от интернета.
- `PROFILE_PASSWORD_HASH` создан от сильного пароля.
- `MAX bot token` хранится в `/profile`, не в репозитории.
- `Webhook secret` можно держать коротким, если камера ходит только через защищенный VPN.
- У пользователя Dahua минимальные права: snapshot.
- Bootstrap подключен локально из `public/assets`, CDN не используется.
- После утечки MAX token, пароля Dahua или `.env` значения перевыпущены.
