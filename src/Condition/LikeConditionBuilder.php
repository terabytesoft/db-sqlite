<?php

declare(strict_types=1);

namespace Yiisoft\Db\Sqlite\Condition;

use Yiisoft\Db\Query\Conditions\LikeConditionBuilder as BaseLikeConditionBuilder;
use Yiisoft\Db\Query\QueryBuilder;

final class LikeConditionBuilder extends BaseLikeConditionBuilder
{
    protected ?string $escapeCharacter = '\\';
    protected QueryBuilder $queryBuilder;

    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }
}
