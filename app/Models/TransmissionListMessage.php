<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransmissionListMessage extends Model
{
    /**
     * Tabela associada à Model
     */
    protected $table = 'transmission_list_messages';

    /**
     * Campos preenchíveis (mass assignment)
     */
    protected $fillable = [
        'user_id',
        'drive_chat_id',
        'drive_message_id',
        'transmission_list_id',
        'status'
    ];

    /**
     * Relacionamento: A mensagem pertence a um usuário (que a enviou).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento: A mensagem pode estar associada a uma lista (para rastreio).
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(TransmissionList::class);
    }
}
