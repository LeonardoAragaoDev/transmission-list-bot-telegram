<?php

namespace App\Http\Controllers;

use App\Models\TransmissionList;
use App\Services\KeyboardService;
use App\Utils\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

class TelegramBotController extends Controller
{

    // Telegram API
    protected Api $telegram;

    // Controllers
    protected CommandController $commandController;
    protected UserController $userController;
    protected ChannelController $channelController;

    // Variáveis globais
    protected string $adminChannelId;
    protected string $adminChannelInviteLink;

    /**
     * Construtor para injeção de dependências.
     */
    public function __construct(
        Api $telegram,
        CommandController $commandController,
        UserController $userController,
        ChannelController $channelController
    ) {
        // Telegram API
        $this->telegram = $telegram;

        // Controllers
        $this->commandController = $commandController;
        $this->userController = $userController;
        $this->channelController = $channelController;

        // IDs e links de canais obtidos das variáveis de ambiente
        $this->adminChannelId = env('TELEGRAM_ADMIN_CHANNEL_ID') ?? '';
        $this->adminChannelInviteLink = env('TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK') ?? '';
    }

    /**
     * Ponto de entrada do Webhook. Direciona a atualização e trata exceções.
     */
    public function handleWebhook(Request $request)
    {
        Log::info("--- NOVO WEBHOOK RECEBIDO ---");
        Log::debug("Corpo da requisição:", $request->all());

        try {
            $update = $this->telegram->getWebhookUpdate();
            $isCallbackQuery = $update->getCallbackQuery();

            if ($isCallbackQuery) {
                $this->handleCallbackQuery($update);
                return response("OK", 200);
            }

            $message = Utils::getMessageFromUpdate($update);

            if (!$message) {
                Log::warning("handleWebhook: Atualização ignorada (sem mensagem/postagem processável).");
                return response("OK", 200);
            }

            $chatType = $message->getChat()->getType();
            Log::info("Tipo de Chat: {$chatType}");

            if ($chatType === "private") {
                $this->handlePrivateChat($update);
            }

        } catch (\Exception $e) {
            Log::error(
                "ERRO CRÍTICO NO WEBHOOK: " . $e->getMessage(),
                ['exception' => $e->getMessage()]
            );
        }

        return response("OK", 200);
    }

    /**
     * Gerencia a resposta aos botões inline.
     */
    protected function handleCallbackQuery(Update $update)
    {
        $callbackQuery = $update->getCallbackQuery();
        $callbackData = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();

        // Resolve o usuário do DB (garantindo consistência com o handlePrivateChat)
        $dbUser = Utils::resolveDbUserFromUpdate($update);

        if (!$dbUser) {
            return; // Ignora se não conseguir identificar o usuário
        }
        $localUserId = $dbUser->id;
        $telegramUserId = $dbUser->telegram_user_id;

        // Envia uma notificação temporária para o usuário
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => 'Processando sua escolha...',
            'show_alert' => false
        ]);

        $isSubscribed = $this->channelController->isUserAdminChannelMember($this->adminChannelId, $telegramUserId, $localUserId, $chatId);

        if (!$isSubscribed) {
            return;
        }

        $returnCommand = $this->commandController->delegateCommand($callbackData, $dbUser, $chatId);

        if ($returnCommand) {
            return;
        } else {
            $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        }

        // Lógica de fluxo avançado
        $flowHandled = $this->handleCallbackFlow($callbackQuery, $dbUser, $chatId);

        // Sempre responde ao CallbackQuery (se o fluxo não o fez)
        if ($callbackQuery->getId()) {
            $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        }
    }

    /**
     * Gerencia o fluxo de configuração em chat privado.
     */
    protected function handlePrivateChat(Update $update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $telegramUser = $message->getFrom();
        $telegramUserId = $telegramUser->getId();

        // Resolve e salva/atualiza o usuário do DB
        $dbUser = $this->userController->saveOrUpdateTelegramUser($telegramUser);
        $localUserId = $dbUser->id;
        $userState = $dbUser->state()->first();
        $text = $message->getText() ? $message->getText() : '';

        // Se for um texto vindo de um botão inline (callback) mas que caiu aqui, ignora.
        if ($update->getCallbackQuery()) {
            return;
        }

        if ($text === "/start") {
            $this->commandController->delegateCommand($text, $dbUser, $chatId);
            return;
        }

        $isSubscribed = $this->channelController->isUserAdminChannelMember($this->adminChannelId, $telegramUserId, $localUserId, $chatId);

        if (!$isSubscribed) {
            return;
        }

        $returnCommand = $this->commandController->delegateCommand($text, $dbUser, $chatId);

        if ($returnCommand) {
            return;
        }

        // --- Lógica de Fluxos (Awaiting Message) ---
        $userState = $dbUser->state()->first();
        if ($userState) {
            // Tratamento de Mensagem Encaminhada para adição de canal
            if ($message->getForwardFromChat() && $userState->state === 'awaiting_channel_message') {
                // Chama o método no ChannelController para processar e salvar canal
                $this->channelController->processForwardedChannel($update, $dbUser, $userState, $chatId);
                return;
            }

            // NOVO: Tratamento da Mensagem para Envio
            if ($userState->state === 'awaiting_message_for_send') {
                // Chama o novo método no ChannelController para processar e salvar a mensagem
                $this->channelController->processMessageForTransmission($message, $dbUser, $userState, $chatId);
                return;
            }
        }

        // Se a mensagem for texto e não for comando nem for tratada por estado
        if (!empty($text) && !str_starts_with($text, '/') && $userState && $userState->state !== 'idle') {
            // Lógica para tratar texto como resposta de estado (ex: nome da lista)
            $handled = $this->commandController->handleExpectedResponse($text, $dbUser, $chatId);
            if ($handled) {
                return;
            }
        }

        // Se a mensagem não for tratada por nenhum dos fluxos
        if ($text) {
            $this->commandController->handleUnknownCommand($chatId);
            return;
        }
    }

    // Em TelegramBotController.php, adicione este novo método à classe

    /**
     * Gerencia ações de fluxo específicas acionadas por botões inline.
     * @return bool Retorna true se um fluxo foi tratado.
     */
    protected function handleCallbackFlow($callbackQuery, $dbUser, $chatId): bool
    {
        $callbackData = $callbackQuery->getData();

        // 1. Tratamento da seleção de lista (Formato: select_list:ID)
        if (str_starts_with($callbackData, 'select_list:')) {
            $listId = (int) explode(':', $callbackData)[1];

            // 1.1 Busca a lista no DB e garante que pertence ao usuário
            $list = TransmissionList::where('user_id', $dbUser->id)
                ->where('id', $listId)
                ->first();

            if (!$list) {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ *Erro:* A lista selecionada não foi encontrada ou não pertence a você.",
                    "parse_mode" => "Markdown",
                ]);
                return true;
            }

            // 1.2 Atualiza o estado do usuário para aguardar a mensagem
            $userState = $dbUser->state()->firstOrNew(['user_id' => $dbUser->id]);
            $userState->state = 'awaiting_message_for_send';

            // Salva o ID da lista selecionada no campo 'data'
            $userState->data = ['transmission_list_id' => $list->id];
            $userState->save();

            // 1.3 Envia a instrução de próximo passo
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "✅ Lista *\"{$list->name}\"* selecionada!\n\nAgora, por favor, *envie ou encaminhe a mensagem* (texto, foto, vídeo, etc.) que você deseja enviar para todos os canais desta lista.",
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardService::cancel(), // Permite cancelar o fluxo
            ]);

            return true;
        }

        // Nenhuma ação de fluxo tratada
        return false;
    }

}
