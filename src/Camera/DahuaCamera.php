<?php

declare(strict_types=1);

namespace App\Camera;

final class DahuaCamera
{
    public function __construct(
        private string $snapshotUrl,
        private string $user,
        private string $password,
    ) {
    }

    public function getSnapshot(): SnapshotResult
    {
        $ch = curl_init($this->snapshotUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
            CURLOPT_USERPWD => $this->user . ':' . $this->password,
            CURLOPT_TIMEOUT => 5,
        ]);

        $image = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        return new SnapshotResult(
            is_string($image) ? $image : null,
            $httpCode,
            $error ?: null,
        );
    }
}
