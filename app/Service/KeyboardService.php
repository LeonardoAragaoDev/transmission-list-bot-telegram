<?php

namespace App\Services;

class KeyboardService
{
    public static function start(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Entrar no Canal', 'url' => env('TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK') ?? ''],
                ],
            ]
        ]);
    }
}
