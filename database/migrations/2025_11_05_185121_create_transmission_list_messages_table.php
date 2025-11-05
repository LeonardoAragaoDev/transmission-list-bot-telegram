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
        Schema::create('transmission_list_messages', function (Blueprint $table) {
            $table->id();

            // Usuário que originou o comando /send
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // ID do Canal Drive no Telegram (salvo no .env ou settings do bot)
            $table->bigInteger('drive_chat_id');

            // ID da mensagem SALVA no Canal Drive.
            // Este é o ID crucial para o método 'forwardMessage'
            $table->bigInteger('drive_message_id')->unique();

            // Referência à lista que o usuário escolheu para o envio
            // Pode ser nulo se for uma mensagem 'rascunho'
            $table->foreignId('transmission_list_id')->nullable()->constrained('transmission_lists');

            // Status do envio (ex: 'pending', 'sent', 'canceled')
            $table->string('status', 50)->default('pending');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transmission_list_messages');
    }
};
