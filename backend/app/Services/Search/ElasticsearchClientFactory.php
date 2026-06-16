<?php

namespace App\Services\Search;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

class ElasticsearchClientFactory
{
    public static function make(): Client
    {
        return ClientBuilder::create()
            ->setHosts([config('elasticsearch.host')])
            ->build();
    }
}
