<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Max Notify Lists</title>
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
            <a class="btn btn-outline-light btn-sm" href="/profile">Профиль</a>
            <a class="btn btn-light btn-sm" href="/profile/lists">Списки</a>
            <a class="btn btn-outline-light btn-sm" href="/profile?logout=1">Выйти</a>
        </div>
    </div>
</nav>

<main class="container-fluid profile-shell py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Клиенты и камеры</h1>
            <div class="text-secondary">Общий список всех получателей MAX и всех источников Dahua/NVR.</div>
        </div>
        <div class="text-lg-end small text-secondary">
            <div>Клиентов: <strong><?= count($clients) ?></strong></div>
            <div>Камер: <strong><?= count($cameras) ?></strong></div>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $this->e($flash) ?></div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $this->e($error) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <section class="col-12 col-xl-5">
            <div class="card profile-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Клиенты MAX</span>
                    <a class="btn btn-outline-primary btn-sm" href="/profile">Добавить</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                        <tr>
                            <th>Имя</th>
                            <th>chat_id</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td><?= $this->e($client['name']) ?></td>
                                <td><code><?= $this->e($client['max_chat_id']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($clients === []): ?>
                            <tr>
                                <td class="text-secondary" colspan="2">Клиенты еще не добавлены.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="col-12 col-xl-7">
            <div class="card profile-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Камеры Dahua/NVR</span>
                    <a class="btn btn-outline-primary btn-sm" href="/profile">Добавить</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                        <tr>
                            <th>Название</th>
                            <th>Источник</th>
                            <th>Клиенты</th>
                            <th>События</th>
                            <th>Snapshot</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cameras as $camera): ?>
                            <tr>
                                <td><?= $this->e($camera['label']) ?></td>
                                <td><code><?= $this->e($camera['source']) ?></code></td>
                                <td><?= $this->e($camera['client_names'] ?? '') ?></td>
                                <td><code><?= $this->e($camera['allowed_rules'] ?: 'Все') ?></code></td>
                                <td class="small"><code><?= $this->e($camera['snapshot_url']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($cameras === []): ?>
                            <tr>
                                <td class="text-secondary" colspan="5">Камеры еще не добавлены.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</main>

<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
