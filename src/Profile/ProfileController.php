<?php

declare(strict_types=1);

namespace App\Profile;

final class ProfileController
{
    private ?ProfileSettings $settings = null;

    public function __construct(
        private ProfileRepository $repository,
        private string $username,
        private string $passwordHash,
        private string $viewPath,
    ) {
    }

    public function handle(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (isset($_GET['logout'])) {
            unset($_SESSION['profile_authenticated']);
            header('Location: /profile');

            return;
        }

        if (!$this->isAuthenticated()) {
            $this->handleLogin();

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $redirectTo = $this->redirectPath();
            $this->handlePost();
            header('Location: ' . $redirectTo);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderRoute();
    }

    private function handleLogin(): void
    {
        if ($this->username === '' || $this->passwordHash === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Profile auth is not configured';

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if (hash_equals($this->username, $username) && password_verify($password, $this->passwordHash)) {
                $_SESSION['profile_authenticated'] = true;
                header('Location: /profile');

                return;
            }

            $_SESSION['profile_flash_error'] = 'Неверный логин или пароль.';
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderLogin();
    }

    private function handlePost(): void
    {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'add_client') {
                $this->repository->addClient(
                    trim((string) ($_POST['name'] ?? '')),
                    trim((string) ($_POST['max_chat_id'] ?? '')),
                );

                $_SESSION['profile_flash'] = 'Клиент добавлен.';
            }

            if ($action === 'update_settings') {
                $this->repository->updateSettings(
                    trim((string) ($_POST['max_bot_token'] ?? '')),
                    trim((string) ($_POST['webhook_secret'] ?? '')),
                );

                $_SESSION['profile_flash'] = 'Настройки сохранены.';
            }

            if ($action === 'delete_client') {
                $this->repository->deleteClient((int) ($_POST['id'] ?? 0));

                $_SESSION['profile_flash'] = 'Клиент удален.';
            }

            if ($action === 'update_client') {
                $this->repository->updateClient(
                    (int) ($_POST['id'] ?? 0),
                    trim((string) ($_POST['name'] ?? '')),
                    trim((string) ($_POST['max_chat_id'] ?? '')),
                );

                $_SESSION['profile_flash'] = 'Клиент обновлен.';
            }

            if ($action === 'add_camera') {
                $clientIds = array_map('intval', $_POST['client_ids'] ?? []);

                $this->repository->addCamera($this->cameraDataFromPost(), $clientIds);

                $_SESSION['profile_flash'] = 'Камера добавлена.';
            }

            if ($action === 'delete_camera') {
                $this->repository->deleteCamera((int) ($_POST['id'] ?? 0));

                $_SESSION['profile_flash'] = 'Камера удалена.';
            }

            if ($action === 'update_camera') {
                $clientIds = array_map('intval', $_POST['client_ids'] ?? []);

                $this->repository->updateCamera(
                    (int) ($_POST['id'] ?? 0),
                    $this->cameraDataFromPost(),
                    $clientIds,
                );

                $_SESSION['profile_flash'] = 'Камера обновлена.';
            }
        } catch (\Throwable $exception) {
            $_SESSION['profile_flash_error'] = $exception->getMessage();
        }
    }

    private function render(): string
    {
        $clients = $this->repository->clients();
        $cameras = $this->repository->cameras();
        $settings = $this->settings();
        $flash = $_SESSION['profile_flash'] ?? null;
        $error = $_SESSION['profile_flash_error'] ?? null;

        unset($_SESSION['profile_flash'], $_SESSION['profile_flash_error']);

        return $this->view('profile/index.php', [
            'clients' => $clients,
            'cameras' => $cameras,
            'settings' => $settings,
            'flash' => $flash,
            'error' => $error,
            'ruleOptions' => $this->ruleOptions(),
        ]);
    }

    private function renderRoute(): string
    {
        return match ($this->currentPath()) {
            '/profile/lists' => $this->renderLists(),
            default => $this->render(),
        };
    }

    private function renderLists(): string
    {
        $clients = $this->repository->clients();
        $cameras = $this->repository->cameras();
        $flash = $_SESSION['profile_flash'] ?? null;
        $error = $_SESSION['profile_flash_error'] ?? null;

        unset($_SESSION['profile_flash'], $_SESSION['profile_flash_error']);

        return $this->view('profile/lists.php', [
            'clients' => $clients,
            'cameras' => $cameras,
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    private function cameraDataFromPost(): array
    {
        $label = trim((string) ($_POST['label'] ?? ''));
        $source = $this->source((string) ($_POST['source'] ?? ''));

        if ($source === '') {
            $source = $this->source($label);
        }

        $snapshotUrl = trim((string) ($_POST['snapshot_url'] ?? ''));

        if ($snapshotUrl === '') {
            $snapshotUrl = $this->snapshotUrlFromParts(
                (string) ($_POST['camera_host'] ?? ''),
                (string) ($_POST['camera_channel'] ?? ''),
            );
        }

        if ($source === '') {
            throw new \RuntimeException('Не удалось сформировать source камеры. Заполните название или source вручную.');
        }

        if ($snapshotUrl === '') {
            throw new \RuntimeException('Не удалось сформировать snapshot URL. Заполните IP/host камеры или Snapshot URL вручную.');
        }

        return [
            'source' => $source,
            'label' => $label,
            'snapshot_url' => $snapshotUrl,
            'username' => trim((string) ($_POST['username'] ?? '')),
            'password' => trim((string) ($_POST['password'] ?? '')),
            'allowed_rules' => $this->rules($_POST['allowed_rules'] ?? []),
        ];
    }

    private function source(string $source): string
    {
        $source = \function_exists('mb_strtolower')
            ? \mb_strtolower(trim($source), 'UTF-8')
            : strtolower(trim($source));
        $source = strtr($source, $this->transliterationMap());
        $source = \preg_replace('/[^a-z0-9_-]+/', '_', $source) ?? '';
        $source = \preg_replace('/_+/', '_', $source) ?? '';

        return trim($source, '_-');
    }

    private function snapshotUrlFromParts(string $host, string $channel): string
    {
        $host = trim($host);

        if ($host === '') {
            return '';
        }

        if (!\preg_match('/^https?:\/\//i', $host)) {
            $host = 'http://' . $host;
        }

        $host = rtrim($host, '/');
        $channelNumber = \ctype_digit($channel) && (int) $channel > 0 ? (int) $channel : 1;

        return $host . '/cgi-bin/snapshot.cgi?channel=' . $channelNumber;
    }

    private function transliterationMap(): array
    {
        return [
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'д' => 'd',
            'е' => 'e',
            'ё' => 'e',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'h',
            'ц' => 'c',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'sch',
            'ъ' => '',
            'ы' => 'y',
            'ь' => '',
            'э' => 'e',
            'ю' => 'yu',
            'я' => 'ya',
        ];
    }

    private function rules(array|string $rules): string
    {
        if (is_string($rules)) {
            return trim($rules);
        }

        $allowed = array_keys($this->ruleOptions());

        return implode(',', array_values(array_intersect($allowed, $rules)));
    }

    private function renderLogin(): string
    {
        $error = $_SESSION['profile_flash_error'] ?? null;
        unset($_SESSION['profile_flash_error']);

        return $this->view('profile/login.php', [
            'error' => $error,
        ]);
    }

    private function view(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require $this->viewPath . '/' . $template;

        return (string) ob_get_clean();
    }

    private function isAuthenticated(): bool
    {
        return ($_SESSION['profile_authenticated'] ?? false) === true;
    }

    private function currentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/profile', PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') ?: '/profile' : '/profile';
    }

    private function redirectPath(): string
    {
        $redirectTo = (string) ($_POST['redirect_to'] ?? $this->currentPath());

        return in_array($redirectTo, ['/profile', '/profile/lists'], true) ? $redirectTo : '/profile';
    }

    public function csvContains(?string $csv, string $value): bool
    {
        $items = array_filter(array_map('trim', explode(',', $csv ?? '')));

        return in_array($value, $items, true);
    }

    public function selectedClientIds(?string $csv): array
    {
        return array_map('intval', array_filter(array_map('trim', explode(',', $csv ?? ''))));
    }

    public function snapshotHost(?string $snapshotUrl): string
    {
        $parts = \parse_url($snapshotUrl ?? '');

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $parts['host'] . $port;
    }

    public function snapshotChannel(?string $snapshotUrl): string
    {
        $parts = \parse_url($snapshotUrl ?? '');

        if (!is_array($parts) || empty($parts['query'])) {
            return '1';
        }

        \parse_str($parts['query'], $query);
        $channel = (string) ($query['channel'] ?? '1');

        return \ctype_digit($channel) && (int) $channel > 0 ? $channel : '1';
    }

    public function webhookCommands(array $camera): array
    {
        $rules = array_filter(array_map('trim', explode(',', $camera['allowed_rules'] ?? '')));

        if ($rules === []) {
            $rules = array_keys($this->ruleOptions());
        }

        return array_map(
            fn (string $rule): string => $this->webhookCommand((string) $camera['source'], $rule),
            $rules,
        );
    }

    public function webhookCommandText(array $camera): string
    {
        return implode("\n", $this->webhookCommands($camera));
    }

    public function ruleLabel(string $rule): string
    {
        return $this->ruleOptions()[$rule] ?? $rule;
    }

    private function webhookCommand(string $source, string $rule): string
    {
        return '/w?' . http_build_query([
            's' => $this->settings()->webhookSecret,
            'e' => 'ivs',
            'c' => $source,
            'r' => $rule,
        ]);
    }

    private function settings(): ProfileSettings
    {
        return $this->settings ??= $this->repository->settings();
    }

    private function ruleOptions(): array
    {
        return [
            'line_crossing' => 'Пересечение линии',
            'intrusion' => 'Вторжение',
            'human_detection' => 'Человек',
            'vehicle_detection' => 'Транспорт',
            'motion' => 'Движение',
            'smd' => 'SMD',
        ];
    }

    public function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
