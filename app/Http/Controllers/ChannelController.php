<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\Channel;
use App\Models\UserState;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Chat as TelegramChatObject;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Update;

class ChannelController extends Controller
{
    protected Api $telegram;
    protected string $adminChannelInviteLink;

    public function __construct(Api $telegram)
    {
        $this->telegram = $telegram;
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
}
