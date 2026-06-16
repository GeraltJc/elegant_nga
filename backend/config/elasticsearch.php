<?php

return [
    'host' => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),

    'indexes' => [
        'threads' => env('ELASTICSEARCH_THREADS_INDEX', 'nga_threads'),
        'posts' => env('ELASTICSEARCH_POSTS_INDEX', 'nga_posts'),
    ],
];
