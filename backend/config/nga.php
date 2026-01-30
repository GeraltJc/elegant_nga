<?php

return [
    'html_purifier' => [
        'definition_id' => env('NGA_HTMLPURIFIER_DEFINITION_ID', 'nga_html'),
        'definition_rev' => (int) env('NGA_HTMLPURIFIER_DEFINITION_REV', 1),
    ],
];
