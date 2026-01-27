# 🚀 Transmission List Bot

**Gerenciamento e Transmissão Multi-Canais para Telegram**

O **Transmission List Bot** é uma solução poderosa de automação para administradores que gerenciam múltiplos canais no Telegram. Ele permite agrupar canais em listas temáticas e realizar disparos em massa de forma organizada, segura e profissional.

Diferente de sistemas de encaminhamento comuns, este bot utiliza o método `copyMessage`, garantindo que o conteúdo chegue aos destinos de forma limpa, sem o rótulo de "Encaminhado de..." e preservando a privacidade da origem.

---

## ✨ Recursos Principais

- **Gestão de Listas:** Crie, renomeie e organize listas de transmissão (ex: "Canais de Promoções", "Canais de Notícias").
- **Transmissão Limpa:** Envio via `copyMessage`, replicando textos, mídias e botões inline sem rastros de origem.
- **Canal de Armazenamento (Drive):** Sistema que armazena a mensagem temporariamente em um canal privado para validação antes do disparo final.
- **Fluxo de Confirmação:** Interface interativa com botões de "Confirmar" ou "Cancelar" para evitar envios acidentais.
- **Máquina de Estados:** Controle rigoroso de fluxo através do `UserState`, garantindo que o bot saiba exatamente o que o usuário está configurando no momento.

---

## 🔗 Comunidade e Suporte

| Recurso                | Link                                                        |
| :--------------------- | :---------------------------------------------------------- |
| **Bot Oficial** | 👉 [@TransmissionListBot](https://t.me/TransmissionListBot)    |
| **Canal de Novidades** | 👉 [@BotNovidades](https://t.me/BotNovidades)               |
| **Desenvolvedor** | 👉 [Leonardo Aragão](https://t.me/LeonardoAragao)           |

---

## 🛠️ Instalação e Configuração Local

Este bot é construído com **PHP 8.2+** usando o framework **Laravel** e a biblioteca `irazasyed/telegram-bot-sdk`.

### Pré-requisitos

1. PHP e Composer instalados.
2. Banco de Dados (MySQL, PostgreSQL ou SQLite).
3. Uma ferramenta de túnel (como **Ngrok** ou **Expose**) para receber Webhooks localmente.

### 1. Clonar o Repositório

```bash
git clone [https://github.com/LeonardoAragaoDev/transmission-list-bot-telegram.git](https://github.com/LeonardoAragaoDev/transmission-list-bot-telegram.git)
cd transmission-list-bot-telegram
```

### 2. Instalar dependências

```bash
composer install
```

### 3. Configuração do Ambiente (.env)

```env
# --- Configuração Geral do Laravel ---
APP_NAME="Next Message Bot"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000 # Substitua pela sua URL pública (ngrok/Expose)

# --- Configuração do Banco de Dados ---
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=db
DB_USERNAME=user
DB_PASSWORD=pass

# --- Configuração do Telegram ---
TELEGRAM_BOT_TOKEN="SEU_TOKEN_DO_BOT_AQUI"

# IDs de canais essenciais para o funcionamento do bot
# Estes IDs devem ser obtidos do seu BotFather e canais criados.
# O STORAGE_CHANNEL_ID é um canal privado onde o bot armazena as mensagens de resposta.
TELEGRAM_STORAGE_CHANNEL_ID="-100XXXXXXXXXXXXXXXX"
TELEGRAM_ADMIN_CHANNEL_ID="-100YYYYYYYYYYYYYYYYY"
TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK="[https://t.me/c/](https://t.me/c/)..." # Link de convite do seu canal admin/novidades
```

### 4. Rodar Migrações

```bash
php artisan migrate
```

### 5. Iniciar o Servidor (e o Túnel Público)

Inicie o servidor local do Laravel:

```bash
php artisan serve
```

Em uma janela separada, inicie o túnel público (Ex: ngrok) e anote a URL gerada (por exemplo, `https://abcdefg.ngrok-free.app`).

### 6. Configurar o Webhook do Telegram

Finalmente, você deve informar ao Telegram qual URL ele deve usar para enviar as atualizações (webhooks). Substitua `TOKEN_DO_SEU_BOT` e `URL_PUBLICA_NA_WEB` na URL abaixo e acesse-a no seu navegador:

```bash
https://api.telegram.org/botTOKEN_DO_SEU_BOT/setWebhook?url=URL_PUBLICA_NA_WEB/api/telegram/webhook
```

Exemplo com ngrok:

```bash
https://api.telegram.org/bot123456:ABC-DEF123456/setWebhook?url=https://abcdefg.ngrok-free.app/api/telegram/webhook
```

Se a configuração for bem-sucedida, você verá uma resposta JSON do Telegram.

### 🤝 Contribuição

Contribuições são sempre bem-vindas! Sinta-se à vontade para abrir uma issue ou enviar um _pull request_.

### 📄 Licença

Este projeto está sob a licença [GPL-3.0](https://github.com/LeonardoAragaoDev/next-message-telegram-bot/blob/master/LICENSE).

### 🧑🏻‍💻 Desenvolvedor
Desenvolvido com ❤️ por [Leonardo Aragão Dev](https://github.com/LeonardoAragaoDev).
