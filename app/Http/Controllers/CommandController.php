<?php

namespace App\Http\Controllers;

use App\Models\TransmissionList;
use App\Models\User;
use App\Models\UserState;
use App\Services\KeyboardService;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class CommandController extends Controller
{

    // Telegram API
    protected Api $telegram;

    // Controllers
    protected ChannelController $channelController;

    // Variáveis globais
    protected string $storageChannelId;
    protected string $adminChannelInviteLink;

    public function __construct(Api $telegram, ChannelController $channelController)
    {
        // Telegram API
        $this->telegram = $telegram;

        // Controllers
        $this->channelController = $channelController;

        // Variáveis globais
        $this->storageChannelId = env('TELEGRAM_STORAGE_CHANNEL_ID') ?? '';
        $this->adminChannelId = env('TELEGRAM_ADMIN_CHANNEL_ID') ?? '';
        $this->adminChannelInviteLink = env('TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK') ?? '';
    }

    /**
     * Delega comandos simples ao CommandController.
     * Retorna true se um comando simples (não-fluxo) foi tratado, false caso contrário.
     */
    public function delegateCommand(string $text, User $dbUser, $chatId): bool
    {
        $localUserId = $dbUser->id;
        $command = str_replace('/', '', explode(' ', $text)[0]);

        // Primeiro, trata comandos de fluxo (que dependem do estado atual)
        if ($this->handleFlowCommand($text, $dbUser, $chatId)) {
            return true;
        }

        // Depois, trata comandos simples
        switch (strtolower($command)) {
            case 'start':
                $this->handleStartCommand($localUserId, $chatId, $dbUser);
                return true;
            case 'commands':
                $this->handleCommandsCommand($chatId);
                return true;
            case 'status':
                $this->handleStatusCommand($chatId);
                return true;
            case 'newlist':
                $this->handleNewListCommand($dbUser, $chatId);
                return true;
            case 'send':
                $this->handleSendCommand($dbUser, $chatId);
                return true;
            case 'cancel':
                $this->handleCancelCommand($dbUser, $chatId);
                return true;
            default:
                // Se não for um comando, ele pode ser uma resposta de texto esperada (ex: nome da lista)
                $this->handleExpectedResponse($text, $dbUser, $chatId);
                return false; // Retorna false para indicar que o texto não era um comando simples
        }
    }

    /**
     * Executa a lógica do comando /start.
     * Envia uma mensagem de boas-vindas e instruções básicas, incluindo o teclado inline.
     *
     * @param int|string $localUserId O ID local do usuário no DB.
     * @param int|string $chatId O ID do chat privado.
     * @param User $dbUser O modelo User do banco de dados.
     */
    public function handleStartCommand($localUserId, $chatId, User $dbUser): void
    {
        Log::info("handleStartCommand: Iniciando para userId: {$localUserId}");

        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "🤖 *Olá, " . $dbUser->first_name . "! Eu sou o TransmissionListBot.*\n\nEnvie o comando /newList para iniciar a criação de uma nova lista de transmissão, para conferir todos os comandos digite /commands e caso esteja configurando e queira cancelar a qualquer momento basta digitar /cancel.\n\nPara usar o bot, você deve estar inscrito no nosso [Canal Oficial]({$this->adminChannelInviteLink}).",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::start(),
        ]);
    }

    /**
     * Executa a lógica do comando /commands.
     * Lista todos os comandos disponíveis para o usuário de forma organizada.
     *
     * @param int|string $chatId O ID do chat privado.
     */
    public function handleCommandsCommand($chatId): void
    {
        // 1. Definição dos comandos em um array (fácil manutenção)
        $commands = [
            '/start' => 'Iniciar o bot',
            '/newList' => 'Configurar uma lista de transmissão',
            '/send' => 'Enviar mensagem para uma lista selecionada',
            '/status' => 'Verificar status do bot',
            '/cancel' => 'Cancelar qualquer fluxo ativo',
        ];

        // 2. Construção da string da mensagem
        $commandList = '';
        foreach ($commands as $command => $description) {
            $commandList .= "`" . $command . "` - " . $description . "\n";
        }

        $messageText = "⚙️ *Comandos Disponíveis* ⚙️\n\n" .
            "Use os comandos abaixo para interagir com o bot:\n\n" .
            $commandList;

        // 3. Envio da mensagem
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => $messageText,
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::newListListCommand()
        ]);
    }

    /**
     * Executa a lógica do comando /status.
     *
     * @param int|string $chatId O ID do chat privado.
     */
    public function handleStatusCommand($chatId): void
    {
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "✅ *O Bot tá on!*",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::newListListCommand(),
        ]);
    }

    /**
     * Executa a lógica do comando /cancel.
     * Limpa o estado do usuário e exclui a mensagem temporária no canal drive, se houver.
     *
     * @param int|string $localUserId O ID local do usuário no DB.
     * @param int|string $chatId O ID do chat privado.
     */
    public function handleUnknownCommand($chatId): void
    {
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "Comando não reconhecido. Use /commands para ver a lista.",
            "parse_mode" => "Markdown",
        ]);
    }

    /**
     * Executa a lógica do comando /cancel.
     * Limpa o estado do usuário.
     *
     * @param User $dbUser
     * @param int|string $chatId
     */
    public function handleCancelCommand(User $dbUser, $chatId): void
    {
        // 1. RECUPERA O ESTADO ATUAL ANTES DE ZERÁ-LO
        $userState = $dbUser->state()->first();
        $listIdToDelete = null;
        $messageText = "❌ *Operação cancelada!* Seu fluxo de configuração foi limpo.";

        // 2. Tenta extrair o ID da lista se o estado for um dos fluxos de criação e houver dados
        if ($userState && $userState->data) {
            // O campo 'data' é configurado com um cast no Eloquent e já é um array/objeto.
            $stateData = $userState->data;

            if (is_array($stateData) && isset($stateData['current_list_id'])) {
                $listIdToDelete = $stateData['current_list_id'];
            }
        }

        // 3. ZERA O ESTADO (Sempre reseta para 'idle')
        $dbUser->state()->updateOrCreate(
            ['user_id' => $dbUser->id],
            ['state' => 'idle', 'data' => null]
        );

        // 4. Tenta apagar a lista (se o ID existir e o objeto puder ser encontrado)
        if ($listIdToDelete) {
            $list = TransmissionList::find($listIdToDelete);

            if ($list) {
                $listName = $list->name;
                // Deleta a lista. O onDelete('cascade') apagará os canais associados.
                $list->delete();
                $messageText = "❌ *Operação cancelada!* A lista **\"{$listName}\"** e seus canais foram apagados.";
            }
        }

        // 5. Envia a mensagem de cancelamento
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => $messageText,
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::newList(),
        ]);
    }

    /**
     * Executa a lógica do comando /newlist (ou /configure).
     * Inicia o fluxo de criação da lista.
     *
     * @param User $dbUser
     * @param int|string $chatId
     */
    public function handleNewListCommand(User $dbUser, $chatId): void
    {
        // 1. Define o estado do usuário
        $dbUser->state()->updateOrCreate(
            ['user_id' => $dbUser->id],
            ['state' => 'awaiting_list_name', 'data' => null]
        );

        // 2. Envia a instrução
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "📝 *Criando nova lista...*\n\nPor favor, *envie o nome* que você quer dar para esta lista de transmissão (Ex: Clientes VIP, Parceiros Beta).",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::cancel(),
        ]);
    }

    /**
     * Trata a mensagem de texto do usuário fora do contexto de comando,
     * baseando-se no estado atual.
     *
     * @param string $text
     * @param User $dbUser
     * @param int|string $chatId
     */
    public function handleExpectedResponse(string $text, User $dbUser, $chatId): bool
    {
        $userState = $dbUser->state()->first();

        if (!$userState || $userState->state === 'idle') {
            return false; // Não há estado ativo
        }

        switch ($userState->state) {
            case 'awaiting_list_name':
                return $this->processListName($text, $dbUser, $userState, $chatId);
            case 'awaiting_channel_message':
                // A lógica para processar mensagens encaminhadas está em ChannelController
                return false;
            // case 'awaiting_send_message': (será implementado depois)
            default:
                $this->handleUnknownCommand($chatId);
                return false;
        }
    }

    /**
     * Processa o nome da lista fornecido pelo usuário.
     */
    protected function processListName(string $name, User $dbUser, UserState $userState, $chatId): bool
    {
        // Garante que o nome não está vazio
        $name = trim($name);
        if (empty($name)) {
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "O nome da lista não pode ser vazio. Por favor, tente novamente.",
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardService::cancel(),
            ]);
            return true;
        }

        // 1. Cria a lista no DB
        $newList = TransmissionList::create([
            'user_id' => $dbUser->id,
            'name' => $name,
        ]);

        // 2. Atualiza o estado para aguardar os canais
        $userState->state = 'awaiting_channel_message';
        // Salva o ID da nova lista para uso nas próximas interações
        $userState->data = ['current_list_id' => $newList->id];
        $userState->save();

        // 3. Envia a instrução para o próximo passo
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "🎉 Lista *\"{$name}\"* criada com sucesso!\n\nAgora, por favor, *encaminhe uma mensagem* de cada canal (grupos ainda não são aceitos) que você deseja adicionar a esta lista.\n\nQuando terminar, digite /done.",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::cancel(),
        ]);

        return true;
    }


    /**
     * Executa a lógica do comando /done (agora pode vir de um callback).
     *
     * @param string $text
     * @param User $dbUser
     * @param int|string $chatId
     * @return bool
     */
    public function handleFlowCommand(string $text, User $dbUser, $chatId): bool
    {
        $command = str_replace('/', '', explode(' ', $text)[0]);

        if (strtolower($command) === 'done') {
            // Lógica para finalizar a adição de canais
            $userState = $dbUser->state()->first();
            if ($userState && $userState->state === 'awaiting_channel_message') {
                $userState->state = 'idle';
                $userState->data = null;
                $userState->save();

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "✅ Adição de canais finalizada! Sua lista está pronta para uso.",
                    "parse_mode" => "Markdown",
                    "reply_markup" => KeyboardService::newList(),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Executa a lógica do comando /send.
     * Inicia o fluxo de envio de mensagens, listando as listas de transmissão.
     *
     * @param User $dbUser O usuário local no DB.
     * @param int|string $chatId O ID do chat privado.
     */
    public function handleSendCommand(User $dbUser, $chatId): void
    {
        // 1. Busca todas as listas de transmissão do usuário
        $lists = TransmissionList::where('user_id', $dbUser->id)->get();

        if ($lists->isEmpty()) {
            // Se o usuário não tiver listas, informa e sugere criar uma.
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "⚠️ Você ainda não possui nenhuma Lista de Transmissão. Utilize o comando /newList para criar sua primeira lista!",
                "parse_mode" => "Markdown",
            ]);
            return;
        }

        // 2. Constrói o teclado inline com as listas
        $keyboard = [];
        foreach ($lists as $list) {
            // Usa o ID da lista no callback_data para identificação.
            // O prefixo 'select_list:' será usado no próximo passo para identificar a ação.
            $keyboard[] = [
                ['text' => "{$list->name}", 'callback_data' => "select_list:{$list->id}"]
            ];
        }

        // Adiciona um botão de cancelamento
        $keyboard[] = [['text' => '❌ Cancelar Envio', 'callback_data' => '/cancel']];

        $replyMarkup = [
            'inline_keyboard' => $keyboard,
        ];

        // 3. Envia a mensagem com as listas disponíveis
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "✉️ *Selecione a Lista de Transmissão* para a qual você deseja enviar a próxima mensagem:",
            "parse_mode" => "Markdown",
            "reply_markup" => json_encode($replyMarkup)
        ]);
    }
}