<?php

declare(strict_types=1);

namespace App\Webhook;

final class EventMessageFormatter
{
    public function format(WebhookRequest $request, ?string $sourceLabel = null): string
    {
        return implode("\n", [
            '🚨 ' . $this->notificationTitle($request),
            '📍 ' . ($sourceLabel ?? $this->sourceLabel($request->source())),
            '🕒 ' . date('d.m H:i') . ' МСК',
        ]);
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'dahua' => 'Камера Dahua',
            'gate' => 'Ворота',
            'yard' => 'Двор',
            'parking' => 'Парковка',
            default => $this->humanize($source),
        };
    }

    private function eventLabel(string $event): string
    {
        return match ($event) {
            'ivs' => 'Событие аналитики',
            'smd' => 'Обнаружено движение',
            'motion' => 'Движение',
            'alarm' => 'Тревога',
            default => $this->humanize($event),
        };
    }

    private function ruleLabel(string $rule): string
    {
        return match ($rule) {
            'line_crossing' => 'Пересечение линии',
            'intrusion' => 'Вторжение в область',
            'tripwire' => 'Пересечение виртуальной линии',
            'human_detection' => 'Обнаружен человек',
            'vehicle_detection' => 'Обнаружен транспорт',
            'smd' => 'SMD-событие',
            'motion' => 'Движение',
            default => $this->humanize($rule),
        };
    }

    private function notificationTitle(WebhookRequest $request): string
    {
        $rule = $request->rule();

        if ($rule !== '') {
            return $this->ruleLabel($rule);
        }

        return $this->eventLabel($request->eventName());
    }

    private function humanize(string $value): string
    {
        $value = str_replace(['_', '-'], ' ', $value);
        $value = trim($value);

        if ($value === '') {
            return 'Неизвестно';
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}
