<?php
defined('TYPO3') or die();

// Register plugin
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'AiSemanticSearch',
    'Search',
    [
        \PITS\AiSemanticSearch\Controller\SearchController::class => 'index, search',
    ],
    [
        \PITS\AiSemanticSearch\Controller\SearchController::class => 'search',
    ]
);

// Register event listeners
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = 
    \PITS\AiSemanticSearch\EventListener\PageEventListener::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] = 
    \PITS\AiSemanticSearch\EventListener\PageEventListener::class;