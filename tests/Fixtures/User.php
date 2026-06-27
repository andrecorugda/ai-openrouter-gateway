<?php

declare(strict_types=1);

namespace Andre\AiGateway\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * Minimal Sanctum-capable user backed by the default Testbench `users` table.
 * Used by the feature test to mint personal access tokens.
 */
class User extends Authenticatable implements HasApiTokensContract
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}
