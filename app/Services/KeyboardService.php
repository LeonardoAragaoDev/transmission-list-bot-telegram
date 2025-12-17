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

    public static function newListLists(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Nova Lista', 'callback_data' => '/newList'],
                ],
                [
                    ['text' => 'Listas', 'callback_data' => '/lists'],
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

    /**
     * Teclado com todas as listas de transmissão do usuário.
     * Formato do callback_data: list_view:{list_id}
     */
    public static function listLists($lists): string
    {
        $keyboard = [];
        foreach ($lists as $list) {
            // Callback para vizualizar/gerenciar a lista: list_view:{id}
            $keyboard[] = [
                ['text' => "{$list->name} ({$list->channels->count()})", 'callback_data' => "list_view:{$list->id}"]
            ];
        }

        $keyboard[] = [
            ['text' => '➕ Nova Lista', 'callback_data' => '/newList'],
            ['text' => '❌ Fechar', 'callback_data' => 'close_keyboard'],
        ];

        return json_encode(['inline_keyboard' => $keyboard]);
    }

    /**
     * Teclado de gerenciamento de canais de uma lista específica.
     * @param int $listId ID da lista
     * @param Collection $channels Canais associados
     */
    public static function manageListChannels(int $listId, $channels): string
    {
        $keyboard = [];

        // 1. Linha de Ações Principais
        $keyboard[] = [
            ['text' => '➕ Adicionar Canais', 'callback_data' => "list_action:add:{$listId}"],
            ['text' => '✉️ Enviar Mensagem', 'callback_data' => "list_action:send:{$listId}"],
        ];

        // 2. Canais (com botão de exclusão)
        foreach ($channels as $channel) {
            $chatName = $channel->chat_name ?? $channel->chat_id;
            $keyboard[] = [
                ['text' => $chatName, 'callback_data' => "channel_view:{$channel->id}"], // Exibir info (opcional)
                ['text' => '🗑️', 'callback_data' => "channel_action:delete:{$channel->id}"], // Excluir
            ];
        }

        // 3. Linha de Ações Finais
        $keyboard[] = [
            ['text' => '✏️ Renomear Lista', 'callback_data' => "list_action:rename:{$listId}"],
            ['text' => '➖ Excluir Lista', 'callback_data' => "list_action:delete:{$listId}"],
        ];

        $keyboard[] = [
            ['text' => '⬅️ Voltar para Listas', 'callback_data' => '/lists'],
        ];

        return json_encode(['inline_keyboard' => $keyboard]);
    }
}
