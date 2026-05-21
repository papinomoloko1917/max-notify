<?php

declare(strict_types=1);

namespace App\Messenger;

use CURLStringFile;

final class MaxMessenger
{
    public function __construct(
        private string $token,
        private string $chatId,
    ) {
    }

    public function uploadImage(string $image, string $filename): UploadImageResult
    {
        $upload = $this->createUpload();

        if ($upload->url === null) {
            return new UploadImageResult(
                $upload,
                null,
                null,
                'Upload URL was not received',
            );
        }

        $imageFile = new CURLStringFile($image, $filename, 'image/jpeg');
        $ch = curl_init($upload->url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'data' => $imageFile,
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        $data = json_decode(is_string($response) ? $response : '', true);
        $firstPhoto = isset($data['photos']) ? reset($data['photos']) : null;
        $imageToken = is_array($firstPhoto) ? ($firstPhoto['token'] ?? null) : null;

        return new UploadImageResult(
            $upload,
            $imageToken,
            $httpCode,
            $error ?: null,
        );
    }

    public function sendMessage(string $text, ?string $imageToken = null): SendMessageResult
    {
        $body = [
            'text' => $text,
        ];

        if ($imageToken !== null) {
            $body['attachments'] = [
                [
                    'type' => 'image',
                    'payload' => [
                        'token' => $imageToken,
                    ],
                ],
            ];
        }

        $ch = curl_init('https://platform-api.max.ru/messages?chat_id=' . $this->chatId);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        return new SendMessageResult(
            $httpCode,
            $error ?: null,
            is_string($response) ? $response : null,
        );
    }

    private function createUpload(): CreateUploadResult
    {
        $ch = curl_init('https://platform-api.max.ru/uploads?type=image');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->token,
            ],
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        $data = json_decode(is_string($response) ? $response : '', true);
        $uploadUrl = $data['url'] ?? null;

        return new CreateUploadResult(
            $uploadUrl,
            $httpCode,
            $error ?: null,
        );
    }
}
