<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'LinkValidator (rewrite): httpstatus.io',
    'description' => 'Experimental: asks the httpstatus.io API about external URLs the own request could not decide, e.g. behind Cloudflare.',
    'category' => 'misc',
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'state' => 'experimental',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-15.0.99',
            'linkvalidator2' => '0.1.0-0.1.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'GeorgRinger\\Linkvalidator2HttpstatusIo\\' => 'Classes/',
        ],
    ],
];
