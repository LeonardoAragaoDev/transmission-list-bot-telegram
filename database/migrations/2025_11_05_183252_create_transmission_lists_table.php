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
        Schema::create('transmission_lists', function (Blueprint $table) {
            $table->id();

            // Chave estrangeira para o usuário que criou a lista
            $table->foreignId('user_id')
                ->constrained('users') // Garante que o ID existe na tabela 'users'
                ->onDelete('cascade'); // Se o usuário for deletado, a lista também é

            // Nome da lista (ex: "Clientes VIP")
            $table->string('name', 255);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transmission_lists');
    }
};
