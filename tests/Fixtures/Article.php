<?php

namespace Atannex\Workflow\Tests\Fixtures;

use Atannex\Workflow\Concerns\HasWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use HasWorkflow;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['published_at' => 'datetime'];
}
