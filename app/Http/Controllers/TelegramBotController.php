<?php

namespace App\Http\Controllers;

use App\Models\TransmissionList;
use App\Models\TransmissionListMessage;
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

        $userState = $dbUser->state()->firstOrCreate(
            ['user_id' => $dbUser->id],
            ['state' => 'idle', 'data' => null]
        );

        Log::info("User state CALLBACK QUERY: " . $userState->state ?? 'null');

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
        $dbUser = $this->userController->saveOrUpdateTelegramUser($telegramUser);
        $localUserId = $dbUser->id;
        $userState = $dbUser->state()->firstOrCreate(
            ['user_id' => $dbUser->id],
            ['state' => 'idle', 'data' => null]
        );
        $text = $message->getText() ? $message->getText() : '';

        Log::info("User state PRIVATE CHAT: " . $userState->state ?? 'null');

        // Se for um texto vindo de um botão inline (callback) mas que caiu aqui, ignora.
        if ($update->getCallbackQuery()) {
            return;
        }

        if (strtolower($text) === "/start") {
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
        $messageId = $callbackQuery->getMessage()->getMessageId(); // Para edições de mensagem

        // Comando especial para listar as listas (volta para o comando principal)
        if ($callbackData === '/lists') {
            $this->commandController->handleListCommand($dbUser, $chatId);
            return true;
        }

        // Comando especial para fechar o teclado e a lista
        if ($callbackData === 'close_keyboard') {
            // Edita a mensagem removendo o teclado
            $this->telegram->editMessageReplyMarkup([
                "chat_id" => $chatId,
                "message_id" => $messageId,
                "reply_markup" => json_encode(['inline_keyboard' => []])
            ]);
            return true;
        }

        // 1. Tratamento da seleção de lista para **ENVIO** (Formato: select_list:ID)
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

        // 2. Tratamento da Confirmação/Cancelamento de Envio (Formato: confirm_send:ID / cancel_send:ID)
        if (str_starts_with($callbackData, 'confirm_send:') || str_starts_with($callbackData, 'cancel_send:')) {
            $parts = explode(':', $callbackData);
            $action = $parts[0]; // 'confirm_send' ou 'cancel_send'
            $messageIdDb = (int) $parts[1]; // ID da mensagem na tabela transmission_list_messages

            // 2.1 Busca a mensagem de transmissão no DB e garante que pertence ao usuário
            $transmissionMessage = TransmissionListMessage::where('user_id', $dbUser->id)
                ->where('id', $messageIdDb)
                ->with('list') // Assumindo um relacionamento para obter o nome da lista
                ->first();

            if (!$transmissionMessage) {
                $this->telegram->answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->getId(),
                    'text' => '❌ Erro: Mensagem de transmissão não encontrada.',
                    'show_alert' => true
                ]);
                $this->telegram->editMessageReplyMarkup([ // Remove o teclado da mensagem
                    "chat_id" => $chatId,
                    "message_id" => $messageId,
                    "reply_markup" => json_encode(['inline_keyboard' => []]),
                ]);
                return true;
            }

            $listName = $transmissionMessage->list ? $transmissionMessage->list->name : 'Lista Desconhecida';

            // 2.2 Zera o estado do usuário antes de processar
            $userState = $dbUser->state()->firstOrNew(['user_id' => $dbUser->id]);
            $userState->state = 'idle';
            $userState->data = null;
            $userState->save();

            // 2.3 Delega a ação para o ChannelController
            if ($action === 'confirm_send') {
                // Ação de CONFIRMAR
                $this->channelController->handleMessageSend($transmissionMessage, $dbUser, $chatId);

                // Edita a mensagem para informar que o envio foi iniciado
                $this->telegram->editMessageText([
                    "chat_id" => $chatId,
                    "message_id" => $messageId,
                    "text" => "🚀 **Envio Iniciado!** A mensagem para a lista *\"{$listName}\"* está sendo processada e enviada para todos os canais. Você será notificado sobre o status (se necessário).",
                    "parse_mode" => "Markdown",
                ]);

            } elseif ($action === 'cancel_send') {
                // Ação de CANCELAR
                $this->channelController->handleMessageCancel($transmissionMessage, $dbUser, $chatId);

                // Edita a mensagem para informar que o envio foi cancelado
                $this->telegram->editMessageText([
                    "chat_id" => $chatId,
                    "message_id" => $messageId,
                    "text" => "🚫 **Envio Cancelado!** A mensagem para a lista *\"{$listName}\"* não será enviada e foi removida do Drive de Armazenamento.",
                    "parse_mode" => "Markdown",
                ]);
            }

            // Avisa o Telegram que a query foi tratada
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'Ação de envio concluída.',
            ]);

            return true;
        }

        // --- NOVO: 3. Tratamento da Abertura/Visualização da Lista (list_view:ID) ---
        if (str_starts_with($callbackData, 'list_view:')) {
            $listId = (int) explode(':', $callbackData)[1];

            $this->channelController->handleListView($listId, $dbUser, $chatId, $messageId);

            return true;
        }

        // --- NOVO: 4. Tratamento das Ações na Lista (list_action:ACTION:ID) ---
        if (str_starts_with($callbackData, 'list_action:')) {
            $parts = explode(':', $callbackData);
            $action = $parts[1]; // 'add', 'send', 'rename', 'delete'
            $listId = (int) $parts[2];

            // Chamamos um handler no ChannelController para gerenciar estas ações
            $this->channelController->handleListAction($action, $listId, $dbUser, $chatId, $messageId);

            return true;
        }

        // --- NOVO: 5. Tratamento das Ações no Canal (channel_action:ACTION:ID) ---
        if (str_starts_with($callbackData, 'channel_action:')) {
            $parts = explode(':', $callbackData);
            $action = $parts[1]; // 'delete'
            $channelId = (int) $parts[2];

            if ($action === 'delete') {
                $this->channelController->handleDeleteChannel($channelId, $dbUser, $chatId, $messageId);
                return true;
            }

            return false;
        }

        // Nenhuma ação de fluxo tratada
        return false;
    }

}
