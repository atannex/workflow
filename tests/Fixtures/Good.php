<?php

namespace Atannex\Workflow\Tests\Fixtures;

/** A subject kept on its own database ("shop"), as a product with its own data would be. */
class Good extends Article
{
    protected $connection = 'shop';

    protected $table = 'goods';
}
