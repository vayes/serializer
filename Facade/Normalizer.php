<?php

namespace Vayes\Serializer\Facade;

use Vayes\Facade\Facade;

/**
 * @method static normalize($obj, array $ignoredProperties = []): array
 */
class Normalizer extends Facade
{
    /**
     * Returns Namespace of requested Class
     *
     * e.g. return RequestFacade::class;
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \Vayes\Serializer\Normalizer::class;
    }
}