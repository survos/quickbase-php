<?php

declare(strict_types=1);

namespace Survos\Quickbase\Adapter;

use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\RecordStore\Contract\AdapterFactoryInterface;
use Survos\RecordStore\Contract\RecordStoreAdapterInterface;
use Survos\RecordStore\Model\ConnectionConfiguration;

final readonly class QuickbaseAdapterFactory implements AdapterFactoryInterface
{
    public function __construct(private QuickbaseClientInterface $client)
    {
    }

    public function supports(string $driver): bool
    {
        return 'quickbase' === strtolower($driver);
    }

    public function create(ConnectionConfiguration $connection): RecordStoreAdapterInterface
    {
        return new QuickbaseAdapter($this->client);
    }
}
