<?php

declare(strict_types=1);

namespace App\Messenger;

final class SendMessageResult
{
    public function __construct(
        public readonly int $httpCode,
        public readonly ?string $error,
        public readonly ?string $response,
        public readonly string $chatId,
    ) {
    }

    public function toLogContext(): array
    {
        $data = json_decode($this->response ?? '', true);
        $message = is_array($data) ? ($data['message'] ?? null) : null;
        $body = is_array($message) ? ($message['body'] ?? null) : null;

        return [
            'text_http_code' => $this->httpCode,
            'text_error' => $this->error,
            'chat_id' => $this->chatId,
            'message_id' => is_array($body) ? ($body['mid'] ?? null) : null,
            'has_attachment' => is_array($body) && !empty($body['attachments']),
            'response_is_json' => is_array($data),
        ];
    }
}
