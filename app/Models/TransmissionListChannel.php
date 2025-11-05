<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransmissionListChannel extends Model
{
    /**
     * Tabela associada à Model
     */
    protected $table = 'transmission_list_channels';

    /**
     * Campos preenchíveis (mass assignment)
     */
    protected $fillable = [
        'transmission_list_id',
        'chat_id',
        'chat_name',
        'username',
        'type'
    ];

    /**
     * Relacionamento: O canal pertence a uma lista de transmissão.
     */
    public function transmissionList(): BelongsTo
    {
        return $this->belongsTo(TransmissionList::class);
    }
}
