<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransmissionList extends Model
{
    /**
     * Tabela associada à Model
     */
    protected $table = 'transmission_lists';

    /**
     * Campos preenchíveis (mass assignment)
     */
    protected $fillable = ['user_id', 'name'];

    /**
     * Relacionamento: A lista pertence a um usuário.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento: Uma lista possui muitos canais de destino.
     */
    public function channels(): HasMany
    {
        return $this->hasMany(TransmissionListChannel::class);
    }

    /**
     * Relacionamento: Uma lista pode estar associada a várias mensagens enviadas.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TransmissionListMessage::class);
    }
}
