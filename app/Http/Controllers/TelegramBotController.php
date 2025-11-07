<?php

namespace App\Http\Controllers;

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

        $text = $message->getText() ? strtolower($message->getText()) : '';

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

        // Lógica para Mensagens Encaminhadas ---
        if ($message->getForwardFromChat()) {
            $userState = $dbUser->state()->first();
            if ($userState && $userState->state === 'awaiting_channel_message') {
                $this->channelController->processForwardedChannel($update, $dbUser, $userState, $chatId);
                return; // Já tratou a atualização, sai do método.
            }
        }
    }
}
