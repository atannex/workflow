<?php

namespace Atannex\Workflow\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $casts = ['roles' => 'array', 'permissions' => 'array'];

    public $timestamps = false;
}
