<?php

namespace App\Models;

class ProjectAiTaskToken extends AbstractModel
{
    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
