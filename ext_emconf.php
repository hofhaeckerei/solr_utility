<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Solr Utilities',
    'description' => 'This TYPO3 extension provides several utilities for ext:solr',
    'category' => 'misc',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.0-9.9.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'H4ck3r31\\SolrUtility\\' => 'Classes',
        ],
    ],
    'state' => 'stable',
    'uploadfolder' => false,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => 'Oliver Hader',
    'author_email' => 'oliver.hader@typo3.org',
    'author_company' => 'hofhäckerei',
    'version' => '1.0.0',
    'clearcacheonload' => true,
];
