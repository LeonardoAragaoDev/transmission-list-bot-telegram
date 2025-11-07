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
                [
                    ['text' => 'Nova Lista', 'callback_data' => '/newList'],
                ],
                [
                    ['text' => 'Listar Comandos', 'callback_data' => '/commands'],
                ],
            ]
        ]);
    }

    public static function cancel(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Cancelar', 'callback_data' => '/cancel']
                ],
            ]
        ]);
    }

    public static function done(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Concluir', 'callback_data' => '/done']
                ],
            ]
        ]);
    }

    public static function newList(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Nova Lista', 'callback_data' => '/newList'],
                ],
            ]
        ]);
    }

    public static function newListListCommand(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Nova Lista', 'callback_data' => '/newList'],
                ],
                [
                    ['text' => 'Listar Comandos', 'callback_data' => '/commands'],
                ],
            ]
        ]);
    }
}
