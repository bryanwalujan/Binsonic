<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['is_simulated' => 'boolean', 'last_seen_at' => 'immutable_datetime'];
    }

    public function online(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gte(now()->subSeconds(config('monitor.online_seconds')));
    }

    public function rotateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->update(['token_hash' => hash('sha256', $token)]);

        return $token;
    }
}
