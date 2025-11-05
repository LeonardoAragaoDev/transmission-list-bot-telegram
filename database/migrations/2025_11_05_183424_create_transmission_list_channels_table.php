<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transmission_list_channels', function (Blueprint $table) {
            $table->id();

            // Chave estrangeira para a lista a qual este canal pertence
            $table->foreignId('transmission_list_id')
                ->constrained('transmission_lists')
                ->onDelete('cascade'); // Se a lista for deletada, seus canais também são

            // ID do chat (canal ou grupo) no Telegram
            // O ID do chat/canal é tipicamente um inteiro (longo) negativo
            $table->bigInteger('chat_id');

            // Campo opcional para salvar o nome do canal (para referência)
            $table->string('chat_name')->nullable();

            // Username do chat (ex: @meucanal)
            $table->string('username')->nullable();

            // Tipo do chat: 'channel', 'group', 'supergroup', etc.
            $table->string('type');

            // Adiciona uma restrição para evitar IDs de canais duplicados na mesma lista
            $table->unique(['transmission_list_id', 'chat_id']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transmission_list_channels');
    }
};
