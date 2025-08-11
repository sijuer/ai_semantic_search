<?php

defined('TYPO3') or die();

(static function (): void {
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
        'AiSemanticSearch',
        'Search',
        'Search (AiSemanticSearch)',
    );
})();