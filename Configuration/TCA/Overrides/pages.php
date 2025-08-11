<?php
defined('TYPO3') or die();

// Add indexing fields to pages
$tempColumns = [
    'tx_semanticsearch_indexed' => [
        'exclude' => true,
        'label' => 'Indexed for Search',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'items' => [
                [
                    0 => '',
                    1 => '',
                ]
            ],
        ]
    ],
    'tx_semanticsearch_last_indexed' => [
        'exclude' => true,
        'label' => 'Last Indexed',
        'config' => [
            'type' => 'input',
            'renderType' => 'inputDateTime',
            'eval' => 'datetime,int',
            'readOnly' => true,
        ]
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', $tempColumns);