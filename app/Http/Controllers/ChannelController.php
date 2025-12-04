<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\Channel;
use App\Models\TransmissionList;
use App\Models\TransmissionListChannel;
use App\Models\TransmissionListMessage;
use App\Models\User;
use App\Models\UserState;
use App\Services\KeyboardService;
use Exception;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Chat as TelegramChatObject;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Update;

class ChannelController extends Controller
{
    // Telegram API
    protected Api $telegram;

    // Variáveis globais
    protected string $storageChannelId;
    protected string $adminChannelInviteLink;

    public function __construct(Api $telegram)
    {
        // Telegram API
        $this->telegram = $telegram;

        // Variáveis globais
        $this->storageChannelId = env('TELEGRAM_STORAGE_CHANNEL_ID') ?? '';
        $this->adminChannelInviteLink = env('TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK') ?? '';
    }

    /**
     * Verifica se o bot é administrador do canal e tem permissão para postar/enviar.
     * @param string $channelId O ID do chat/canal.
     * @return array Retorna ["is_admin" => bool, "can_post" => bool]
     */
    public function checkBotPermissions(string $channelId): array
    {
        try {
            $botUsername = $this->telegram->getMe()->getUsername();
            $administrators = $this->telegram->getChatAdministrators(["chat_id" => $channelId]);

            $botMember = null;
            $is_admin = false;
            $can_post = false;

            // Encontra o bot na lista de administradores
            foreach ($administrators as $member) {
                if ($member->getUser()->getUsername() === $botUsername) {
                    $botMember = $member;
                    $is_admin = true;
                    break;
                }
            }

            if ($botMember) {
                // "can_post_messages" é a permissão mais crucial para posts de canal.
                // Usaremos "can_post_messages" para verificar se ele pode enviar posts no canal.
                // Assumindo que você usará copyMessage, ele só precisa ser admin com essa permissão.
                // Em canais, "can_post_messages" geralmente significa que ele pode criar novos posts.
                // Para reply/copia, "can_delete_messages" pode ser útil, mas "can_post_messages" é o mínimo.
                $can_post = $botMember->getCanPostMessages() === true;
            }

            return ["is_admin" => $is_admin, "can_post" => $can_post];

        } catch (\Exception $e) {
            // Se o bot não for admin, getChatAdministrators falha com erro 400.
            // O bot deve ser admin para esta verificação funcionar.
            Log::error("Falha ao verificar permissões no canal {$channelId}: " . $e->getMessage());
            return ["is_admin" => false, "can_post" => false];
        }
    }

    /**
     * Verifica se o usuário é membro do canal de administração.
     * @param string $adminChannelId O ID do canal de admin.
     * @param int $userId O ID do Telegram do usuário.
     * @return bool
     */
    public function isUserAdminChannelMember(string $adminChannelId, int $userId, int $localUserId, int $chatId): bool
    {
        $retorno = false;

        // Se o ID do canal admin não estiver configurado, assume-se que a verificação não é necessária.
        if (empty($adminChannelId)) {
            $retorno = false;
        }

        try {
            // Usa getChatMember para verificar o status
            $chatMember = $this->telegram->getChatMember([
                "chat_id" => $adminChannelId,
                "user_id" => $userId,
            ]);
            Log::info("Verificação de membro do canal admin para usuário {$userId} no canal {$adminChannelId}: Status - " . $chatMember->get("status"));
            $status = $chatMember->get("status");

            // O usuário é membro se o status for "member", "administrator" ou "creator".
            $retorno = in_array($status, ["member", "administrator", "creator"]);

        } catch (\Exception $e) {
            // Isso pode falhar se o bot não estiver no canal admin ou se o ID for inválido.
            // O tratamento padrão é negar o acesso ou logar e retornar false.
            Log::error("Falha ao verificar a inscrição do usuário {$userId} no canal admin {$adminChannelId}: " . $e->getMessage());
            // Em caso de falha na API, o mais seguro é impedir o uso.
            $retorno = false;
        }

        if (!$retorno) {
            // Limpa o estado ativo, se houver
            $userState = UserState::where("user_id", $localUserId)->first();
            if ($userState && $userState->state !== 'idle') {
                $userState->state = "idle";
                $userState->data = null;
                $userState->save();
            }

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🔒 *Acesso Negado!* Para usar o bot, você deve estar inscrito no nosso canal oficial. \n\n Por favor, inscreva-se em: [Clique aqui para entrar]({$this->adminChannelInviteLink}) \n\n*⚠️ Alerta:* A não-inscrição fará com que o bot *NÃO envie* as mensagens automáticas configuradas em seus canais.",
                "parse_mode" => "Markdown",
                "disable_web_page_preview" => true,
            ]);
        }

        return $retorno;
    }

    /**
     * Processa uma mensagem encaminhada para extrair e salvar o chat/canal na lista.
     * @param Update $update A atualização completa do Telegram.
     * @param User $dbUser O modelo User do banco de dados.
     * @param UserState $userState O estado atual do usuário.
     * @param int|string $chatId O ID do chat privado.
     */
    public function processForwardedChannel(Update $update, User $dbUser, UserState $userState, $chatId): void
    {
        $message = $update->getMessage();
        $forwardedChat = $message->getForwardFromChat();
        $currentListId = $userState->data['current_list_id'] ?? null;

        if (!$forwardedChat || !$currentListId) {
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "⚠️ *Erro de Fluxo:* Não foi possível identificar o canal ou a lista de destino. Por favor, tente novamente ou digite /cancel.",
                "parse_mode" => "Markdown",
            ]);
            return;
        }

        $chatIdTelegram = $forwardedChat->getId();
        $chatName = $forwardedChat->getTitle() ?? 'N/A';
        $username = $forwardedChat->getUsername();
        $type = $forwardedChat->getType();

        try {
            // O bot deve ser administrador do canal de destino e ter permissão de postagem.
            $permissions = $this->checkBotPermissions($chatIdTelegram);

            if (!$permissions['is_admin']) {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "⚠️ *Falha ao adicionar: Bot não é Admin!*\n\nO bot deve ser *Administrador* no canal/grupo \"{$chatName}\" para poder enviar mensagens.",
                    "parse_mode" => "Markdown",
                ]);
                return;
            }

            if (!$permissions['can_post']) {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "⚠️ *Falha ao adicionar: Permissão de Postagem!*\\n\\nO bot não tem a permissão para *Postar mensagens* (Post Messages) no canal \"{$chatName}\".",
                    "parse_mode" => "Markdown",
                ]);
                return;
            }

            // Verifica se o canal já foi adicionado
            $channelExists = TransmissionListChannel::where([
                'transmission_list_id' => $currentListId,
                'chat_id' => $chatIdTelegram,
            ])->exists();

            if ($channelExists) {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "ℹ️ O canal *\"{$chatName}\"* já foi adicionado a esta lista.",
                    "parse_mode" => "Markdown",
                ]);
                return;
            }

            // 1. Salva o canal na lista
            TransmissionListChannel::create([
                'transmission_list_id' => $currentListId,
                'chat_id' => $chatIdTelegram,
                'chat_name' => $chatName,
                'username' => $username,
                'type' => $type,
            ]);

            // 2. Envia confirmação
            $listCount = TransmissionListChannel::where('transmission_list_id', $currentListId)->count();
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "➕ Canal *\"{$chatName}\"* adicionado!\n\nTotal de canais na lista: *{$listCount}*.\nEncaminhe mais mensagens para adicionar outros canais ou grupos ou digite /done para finalizar.",
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardService::done()
            ]);

        } catch (\Exception $e) {
            Log::error("Falha ao salvar canal encaminhado: " . $e->getMessage(), ['chat_id' => $chatIdTelegram, 'list_id' => $currentListId]);
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "❌ *Erro ao adicionar canal:* Ocorreu um erro no servidor. Tente novamente.",
                "parse_mode" => "Markdown",
            ]);
        }
    }

    /**
     * Processa a mensagem enviada pelo usuário para ser transmitida.
     * Salva a mensagem no canal de storage e inicia a fase de confirmação.
     *
     * @param \Telegram\Bot\Objects\Message $message O objeto da mensagem original do Telegram.
     * @param \App\Models\User $dbUser O usuário local no DB.
     * @param \App\Models\UserState $userState O estado atual do usuário.
     * @param int|string $chatId O ID do chat privado do usuário.
     */
    public function processMessageForTransmission($message, $dbUser, $userState, $chatId): void
    {
        // O ID do canal de storage (drive) é definido no CommandController.php.
        // Você precisa ter acesso a ele. Se não estiver no ChannelController, injete-o no construtor.
        // Pelo que vi, você não tem o storageChannelId no ChannelController.
        // Vamos injetar ou passar a dependência. Por enquanto, assumiremos que ele está disponível.
        if (!$this->storageChannelId) {
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "❌ *Erro de Configuração:* O ID do Canal Drive (STORAGE) não está definido no sistema.",
                "parse_mode" => "Markdown",
            ]);
            return;
        }

        try {
            // 1. Encaminha/Salva a mensagem para o Canal Drive
            $driveMessage = $this->telegram->forwardMessage([
                'chat_id' => $this->storageChannelId, // ID do Canal Drive
                'from_chat_id' => $chatId,      // ID do usuário
                'message_id' => $message->getMessageId(), // ID da mensagem a ser salva
            ]);

            $driveMessageId = $driveMessage->getMessageId();
            $listId = $userState->data['transmission_list_id'] ?? null;

            if (!$listId) {
                throw new Exception("ID da lista de transmissão ausente no estado do usuário.");
            }

            // 2. Registra a mensagem salva no DB
            $transmissionListMessage = TransmissionListMessage::create([
                'user_id' => $dbUser->id,
                'drive_chat_id' => $this->storageChannelId,
                'drive_message_id' => $driveMessageId,
                'transmission_list_id' => $listId,
                'status' => 'pending', // Marca como pendente de envio
            ]);

            // 3. Atualiza o estado do usuário para aguardar a confirmação
            $userState->state = 'awaiting_send_confirmation';
            // Guarda o ID da mensagem de transmissão para a próxima fase
            $userState->data = ['transmission_message_id' => $transmissionListMessage->id];
            $userState->save();

            // 4. Envia o prompt de confirmação
            $list = TransmissionList::find($listId); // Busca a lista para nome
            $listName = $list ? $list->name : 'Lista Desconhecida';

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🎉 *Mensagem Recebida e Salva!*\\n\\nA mensagem acima (que é uma cópia da sua) foi armazenada e está pronta para ser enviada para a lista **\"{$listName}\"**.\\n\\n*Deseja prosseguir com o envio agora?*",
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardService::confirmSend($transmissionListMessage->id), // NOVO TECLADO
            ]);

        } catch (Exception $e) {
            Log::error("Falha ao salvar mensagem para transmissão: " . $e->getMessage(), ['user_id' => $dbUser->id]);
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "❌ *Erro ao salvar mensagem:* Ocorreu um erro no servidor. Verifique se o bot é administrador do Canal Drive e tente novamente.",
                "parse_mode" => "Markdown",
            ]);
        }
    }
}