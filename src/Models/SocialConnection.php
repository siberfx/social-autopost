<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $network
 * @property string $payload Encrypted JSON; read it through DatabaseConnectionStore.
 */
final class SocialConnection extends Model
{
    protected $table = 'social_connections';

    protected $guarded = [];

    protected $hidden = ['payload'];
}
