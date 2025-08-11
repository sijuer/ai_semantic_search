<?php
// ext_emconf.php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Semantic Search with PostgreSQL',
    'description' => 'Semantic search extension using PostgreSQL and pgvector for TYPO3',
    'category' => 'plugin',
    'author' => 'Siju E Raju',
    'author_email' => 'siju.er@pitsolutions.com',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-12.4.99',
        ],
    ],
];