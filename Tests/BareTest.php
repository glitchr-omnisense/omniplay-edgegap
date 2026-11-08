<?php

namespace Omniplay\Edgegap\Tests;

use Omniplay\Edgegap\EdgegapHosting;
use Omniplay\Testing\BareTestCase;

/** omniplay/edgegap's classes load with omnisense/omniplay and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return EdgegapHosting::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
