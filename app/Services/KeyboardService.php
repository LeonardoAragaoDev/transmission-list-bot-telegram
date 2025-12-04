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

    /**
     * Teclado de confirmação para o envio de mensagens.
     * @param int $messageId O ID da TransmissionListMessage.
     */
    public static function confirmSend(int $messageId): string
    {
        // Os callbacks de confirmação serão 'confirm_send:ID' e 'cancel_send:ID'
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '✅ Confirmar Envio', 'callback_data' => "confirm_send:{$messageId}"],
                    ['text' => '❌ Cancelar Envio', 'callback_data' => "cancel_send:{$messageId}"],
                ],
            ]
        ]);
    }
}