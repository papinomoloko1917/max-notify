<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Max Notify Login</title>
    <link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-5 col-lg-4">
            <div class="card">
                <div class="card-header fw-semibold">Вход в Max Notify</div>
                <div class="card-body">
                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger"><?= $this->e($error) ?></div>
                    <?php endif; ?>

                    <form method="post" class="vstack gap-3">
                        <input type="hidden" name="action" value="login">
                        <div>
                            <label class="form-label" for="username">Логин</label>
                            <input class="form-control" id="username" name="username" autocomplete="username" required>
                        </div>
                        <div>
                            <label class="form-label" for="password">Пароль</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-primary" type="submit">Войти</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
