<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Max Notify Profile</title>
    <link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fb;
        }

        .profile-shell {
            max-width: 1440px;
        }

        .profile-card {
            border: 1px solid #dee5ef;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
        }

        .profile-card .card-header {
            background: #fff;
            border-bottom-color: #e8edf5;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-dark navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="/profile">Max Notify</a>
        <div class="d-flex gap-2">
            <a class="btn btn-light btn-sm" href="/profile">Профиль</a>
            <a class="btn btn-outline-light btn-sm" href="/profile/lists">Списки</a>
            <a class="btn btn-outline-light btn-sm" href="/profile?logout=1">Выйти</a>
        </div>
    </div>
</nav>

<main class="container-fluid profile-shell py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Профиль сервиса</h1>
            <div class="text-secondary">Клиенты MAX, камеры Dahua/NVR и основные настройки уведомлений.</div>
        </div>
        <div class="text-lg-end small text-secondary">
            <div>Endpoint: <code>/webhook</code></div>
            <div>Настройки хранятся в MySQL</div>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $this->e($flash) ?></div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $this->e($error) ?></div>
    <?php endif; ?>

    <section class="card profile-card mb-4">
        <div class="card-header fw-semibold">Настройки сервиса</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="update_settings">
                <div class="col-12 col-lg-6">
                    <label class="form-label" for="max-token">MAX bot token</label>
                    <input class="form-control font-monospace" id="max-token" name="max_bot_token" type="password" value="<?= $this->e($settings->maxBotToken) ?>" autocomplete="off" required>
                    <div class="form-text">Токен бота MAX используется только сервером для отправки сообщений.</div>
                </div>
                <div class="col-12 col-lg-6">
                    <label class="form-label" for="webhook-secret">Webhook secret</label>
                    <div class="input-group">
                        <input class="form-control font-monospace" id="webhook-secret" name="webhook_secret" data-role="webhook-secret" type="password" value="<?= $this->e($settings->webhookSecret) ?>" autocomplete="off" required>
                        <button class="btn btn-outline-secondary" data-role="generate-secret" type="button">Сгенерировать</button>
                    </div>
                    <div class="form-text">Новый secret будет длиной 12 символов. Для VPN-сценария этого достаточно, а webhook-команда будет короче.</div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Сохранить настройки</button>
                </div>
            </form>
        </div>
    </section>

    <div class="row g-4">
        <section class="col-12 col-xl-5">
            <div class="card profile-card">
                <div class="card-header fw-semibold">Клиенты MAX</div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="add_client">
                        <div class="col-12">
                            <label class="form-label" for="client-name">Имя клиента</label>
                            <input class="form-control" id="client-name" name="name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="client-chat">MAX chat_id</label>
                            <input class="form-control" id="client-chat" name="max_chat_id" required>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit">Добавить клиента</button>
                        </div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                        <tr>
                            <th>Клиент</th>
                            <th>chat_id</th>
                            <th class="text-end">Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td><?= $this->e($client['name']) ?></td>
                                <td><code><?= $this->e($client['max_chat_id']) ?></code></td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#edit-client-<?= (int) $client['id'] ?>">Изменить</button>
                                        <form method="post" onsubmit="return confirm('Удалить клиента? Он будет отвязан от всех камер.');">
                                            <input type="hidden" name="action" value="delete_client">
                                            <input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
                                            <button class="btn btn-outline-danger" type="submit">Удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="col-12 col-xl-7">
            <div class="card profile-card">
                <div class="card-header fw-semibold">Камеры</div>
                <div class="card-body">
                    <form method="post" class="row g-3 js-camera-form">
                        <input type="hidden" name="action" value="add_camera">
                        <input id="camera-source" name="source" data-role="source" type="hidden">
                        <div class="col-12">
                            <label class="form-label" for="camera-label">Название</label>
                            <input class="form-control" id="camera-label" name="label" data-role="label" required placeholder="Прихожая">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="camera-host">IP или host камеры</label>
                            <input class="form-control" id="camera-host" name="camera_host" data-role="host" required placeholder="10.10.0.117">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="camera-channel">Канал</label>
                            <input class="form-control" id="camera-channel" name="camera_channel" data-role="channel" type="number" min="1" value="1" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="camera-url">Snapshot URL</label>
                            <input class="form-control" id="camera-url" name="snapshot_url" data-role="snapshot-url" placeholder="http://camera-ip/cgi-bin/snapshot.cgi?channel=1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="camera-user">Пользователь Dahua</label>
                            <input class="form-control" id="camera-user" name="username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="camera-password">Пароль Dahua</label>
                            <input class="form-control" id="camera-password" name="password" type="password" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Разрешенные события</label>
                            <div class="row g-2">
                                <?php foreach ($ruleOptions as $rule => $label): ?>
                                    <div class="col-6 col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" id="camera-rule-<?= $this->e($rule) ?>" name="allowed_rules[]" type="checkbox" value="<?= $this->e($rule) ?>">
                                            <label class="form-check-label" for="camera-rule-<?= $this->e($rule) ?>"><?= $this->e($label) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Если ничего не выбрать, будут разрешены все события.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="camera-clients">Клиенты</label>
                            <select class="form-select" id="camera-clients" name="client_ids[]" multiple required size="4">
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= (int) $client['id'] ?>"><?= $this->e($client['name']) ?> (<?= $this->e($client['max_chat_id']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit">Добавить камеру</button>
                        </div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                        <tr>
                            <th>Название</th>
                            <th>Клиенты</th>
                            <th>Rules</th>
                            <th>Webhook для Dahua</th>
                            <th class="text-end">Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cameras as $camera): ?>
                            <tr>
                                <td><?= $this->e($camera['label']) ?></td>
                                <td><?= $this->e($camera['client_names'] ?? '') ?></td>
                                <td><code><?= $this->e($camera['allowed_rules'] ?? '') ?></code></td>
                                <td style="min-width: 360px;">
                                    <div class="vstack gap-2">
                                        <?php foreach ($this->webhookCommands($camera) as $command): ?>
                                            <?php $rule = parse_url($command, PHP_URL_QUERY); parse_str((string) $rule, $commandQuery); ?>
                                            <div>
                                                <div class="small text-muted"><?= $this->e($this->ruleLabel((string) ($commandQuery['rule'] ?? ''))) ?></div>
                                                <div class="input-group input-group-sm">
                                                    <input class="form-control font-monospace js-copy-value" value="<?= $this->e($command) ?>" readonly>
                                                    <button class="btn btn-outline-secondary js-copy-button" type="button">Копировать</button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#edit-camera-<?= (int) $camera['id'] ?>">Изменить</button>
                                        <form method="post" onsubmit="return confirm('Удалить камеру? Ее webhook перестанет отправлять уведомления.');">
                                            <input type="hidden" name="action" value="delete_camera">
                                            <input type="hidden" name="id" value="<?= (int) $camera['id'] ?>">
                                            <button class="btn btn-outline-danger" type="submit">Удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</main>

<?php foreach ($clients as $client): ?>
    <div class="modal fade" id="edit-client-<?= (int) $client['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="update_client">
                    <input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Редактировать клиента</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                    </div>
                    <div class="modal-body vstack gap-3">
                        <div>
                            <label class="form-label" for="edit-client-name-<?= (int) $client['id'] ?>">Имя клиента</label>
                            <input class="form-control" id="edit-client-name-<?= (int) $client['id'] ?>" name="name" value="<?= $this->e($client['name']) ?>" required>
                        </div>
                        <div>
                            <label class="form-label" for="edit-client-chat-<?= (int) $client['id'] ?>">MAX chat_id</label>
                            <input class="form-control" id="edit-client-chat-<?= (int) $client['id'] ?>" name="max_chat_id" value="<?= $this->e($client['max_chat_id']) ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($cameras as $camera): ?>
    <?php $selectedClientIds = $this->selectedClientIds($camera['client_ids'] ?? ''); ?>
    <div class="modal fade" id="edit-camera-<?= (int) $camera['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" class="js-camera-form">
                    <input type="hidden" name="action" value="update_camera">
                    <input type="hidden" name="id" value="<?= (int) $camera['id'] ?>">
                    <input id="edit-camera-source-<?= (int) $camera['id'] ?>" name="source" data-role="source" type="hidden" value="<?= $this->e($camera['source']) ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Редактировать камеру</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="edit-camera-label-<?= (int) $camera['id'] ?>">Название</label>
                                <input class="form-control" id="edit-camera-label-<?= (int) $camera['id'] ?>" name="label" data-role="label" value="<?= $this->e($camera['label']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Webhook для Dahua</label>
                                <div class="vstack gap-2">
                                    <?php foreach ($this->webhookCommands($camera) as $command): ?>
                                        <?php $rule = parse_url($command, PHP_URL_QUERY); parse_str((string) $rule, $commandQuery); ?>
                                        <div>
                                            <div class="small text-muted"><?= $this->e($this->ruleLabel((string) ($commandQuery['rule'] ?? ''))) ?></div>
                                            <div class="input-group">
                                                <input class="form-control font-monospace js-copy-value" value="<?= $this->e($command) ?>" readonly>
                                                <button class="btn btn-outline-secondary js-copy-button" type="button">Копировать</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text">Вставьте в камеру только одну команду под выбранное событие.</div>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="edit-camera-host-<?= (int) $camera['id'] ?>">IP или host камеры</label>
                                <input class="form-control" id="edit-camera-host-<?= (int) $camera['id'] ?>" name="camera_host" data-role="host" value="<?= $this->e($this->snapshotHost($camera['snapshot_url'] ?? '')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="edit-camera-channel-<?= (int) $camera['id'] ?>">Канал</label>
                                <input class="form-control" id="edit-camera-channel-<?= (int) $camera['id'] ?>" name="camera_channel" data-role="channel" type="number" min="1" value="<?= $this->e($this->snapshotChannel($camera['snapshot_url'] ?? '')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="edit-camera-url-<?= (int) $camera['id'] ?>">Snapshot URL</label>
                                <input class="form-control" id="edit-camera-url-<?= (int) $camera['id'] ?>" name="snapshot_url" data-role="snapshot-url" value="<?= $this->e($camera['snapshot_url']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="edit-camera-user-<?= (int) $camera['id'] ?>">Пользователь Dahua</label>
                                <input class="form-control" id="edit-camera-user-<?= (int) $camera['id'] ?>" name="username" value="<?= $this->e($camera['username']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="edit-camera-password-<?= (int) $camera['id'] ?>">Новый пароль Dahua</label>
                                <input class="form-control" id="edit-camera-password-<?= (int) $camera['id'] ?>" name="password" type="password" placeholder="Оставить пустым, чтобы не менять">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Разрешенные события</label>
                                <div class="row g-2">
                                    <?php foreach ($ruleOptions as $rule => $label): ?>
                                        <div class="col-6 col-md-4">
                                            <div class="form-check">
                                                <input
                                                    class="form-check-input"
                                                    id="edit-camera-rule-<?= (int) $camera['id'] ?>-<?= $this->e($rule) ?>"
                                                    name="allowed_rules[]"
                                                    type="checkbox"
                                                    value="<?= $this->e($rule) ?>"
                                                    <?= $this->csvContains($camera['allowed_rules'] ?? '', $rule) ? 'checked' : '' ?>
                                                >
                                                <label class="form-check-label" for="edit-camera-rule-<?= (int) $camera['id'] ?>-<?= $this->e($rule) ?>"><?= $this->e($label) ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text">Если ничего не выбрать, будут разрешены все события.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="edit-camera-clients-<?= (int) $camera['id'] ?>">Клиенты</label>
                                <select class="form-select" id="edit-camera-clients-<?= (int) $camera['id'] ?>" name="client_ids[]" multiple required size="4">
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= (int) $client['id'] ?>" <?= in_array((int) $client['id'], $selectedClientIds, true) ? 'selected' : '' ?>>
                                            <?= $this->e($client['name']) ?> (<?= $this->e($client['max_chat_id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/assets/profile/profile.js"></script>
</body>
</html>
