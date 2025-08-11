<?php
declare(strict_types=1);

namespace PITS\AiSemanticSearch\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2025 Developer <contact@pitsolutions.com>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class IndexService implements SingletonInterface
{
    private PostgreSQLConnectionService $pgService;
    private VectorService $vectorService;
    private ConnectionPool $connectionPool;

    public function __construct()
    {
        $this->pgService = GeneralUtility::makeInstance(PostgreSQLConnectionService::class);
        $this->vectorService = GeneralUtility::makeInstance(VectorService::class);
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    public function indexPage(int $pageId): void
    {
        $pageRecord = $this->getPageRecord($pageId);
        if (!$pageRecord) {
            return;
        }

        $content = $this->extractPageContent($pageRecord);
        $this->indexContent($pageRecord, $content);
    }

    private function getPageRecord(int $pageId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $page = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        return $page ?: null;
    }

    private function extractPageContent(array $pageRecord): array
    {
        $content = [
            'title' => $pageRecord['title'] ?? '',
            'content' => $pageRecord['abstract'] ?? '',
            'url' => $this->generatePageUrl($pageRecord),
        ];

        // Extract content from tt_content elements
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $contentElements = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageRecord['uid'], \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('hidden', 0)
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $contentText = [];
        foreach ($contentElements as $element) {
            if (!empty($element['bodytext'])) {
                $contentText[] = strip_tags($element['bodytext']);
            }
            if (!empty($element['header'])) {
                $contentText[] = strip_tags($element['header']);
            }
        }

        $content['content'] .= ' ' . implode(' ', $contentText);
        $content['content'] = trim($content['content']);

        return $content;
    }

    private function generatePageUrl(array $pageRecord): string
    {
        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        return $cObj->typoLink_URL([
            'parameter' => $pageRecord['uid'],
            'forceAbsoluteUrl' => true,
        ]);
    }

    private function indexContent(array $pageRecord, array $content): void
    {
        try {
            
            // Generate embeddings
            $titleVector = $this->vectorService->generateEmbedding($content['title']);
            $contentVector = $this->vectorService->generateEmbedding($content['content']);

            // Prepare data for PostgreSQL
            $pdo = $this->pgService->getConnection();
            
            $sql = "
                INSERT INTO typo3_search_index (
                    typo3_uid, typo3_table, typo3_pid, title, content, url, 
                    language_uid, root_page_uid, content_vector, title_vector, content_tsvector,
                    content_hash, boost_factor
                ) VALUES (
                    :typo3_uid, :typo3_table, :typo3_pid, :title, :content, :url,
                    :language_uid, :root_page_uid, :content_vector, :title_vector, 
                    to_tsvector('german', :content_text),
                    :content_hash, :boost_factor
                )
                ON CONFLICT (typo3_uid, typo3_table, language_uid) 
                DO UPDATE SET
                    title = EXCLUDED.title,
                    content = EXCLUDED.content,
                    url = EXCLUDED.url,
                    content_vector = EXCLUDED.content_vector,
                    title_vector = EXCLUDED.title_vector,
                    content_tsvector = EXCLUDED.content_tsvector,
                    content_hash = EXCLUDED.content_hash,
                    boost_factor = EXCLUDED.boost_factor,
                    updated_at = CURRENT_TIMESTAMP
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'typo3_uid' => $pageRecord['uid'],
                'typo3_table' => 'pages',
                'typo3_pid' => $pageRecord['pid'],
                'title' => $content['title'],
                'content' => $content['content'],
                'url' => $content['url'],
                'language_uid' => $pageRecord['sys_language_uid'] ?? 0,
                'root_page_uid' => $this->getRootPageId($pageRecord),
                'content_vector' => $this->vectorService->formatVectorForPostgreSQL($contentVector),
                'title_vector' => $this->vectorService->formatVectorForPostgreSQL($titleVector),
                'content_text' => $content['content'],
                //'content_hash' => hash('sha256', $content['content'] . $content['title']),
                'content_hash' => md5($content['content'] . $content['title']),
                //'boost_factor' => $this->calculateBoostFactor($pageRecord, $content),
                'boost_factor' => $pageRecord['doktype'] === 1 ? 1.0 : 0.5,
            ]);

            // Update TYPO3 page record
            $this->updatePageIndexStatus($pageRecord['uid']);

        } catch (\Exception $e) {
            debug('Semantic search indexing failed for page ' . $pageRecord['uid'] . ': ' . $e->getMessage(),);die;
            // Log error
           /* GeneralUtility::sysLog(
                'Semantic search indexing failed for page ' . $pageRecord['uid'] . ': ' . $e->getMessage(),
                'ai_semantic_search',
                GeneralUtility::SYSLOG_SEVERITY_ERROR
            );*/
        }
    }

    private function getRootPageId(array $pageRecord): int
    {
        $rootLine = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Utility\RootlineUtility::class, $pageRecord['uid']);
        $rootLineArray = $rootLine->get();
        
        foreach (array_reverse($rootLineArray) as $page) {
            if ($page['is_siteroot']) {
                return (int)$page['uid'];
            }
        }
        
        return (int)$pageRecord['uid'];
    }

    private function updatePageIndexStatus(int $pageId): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder
            ->update('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            )
            ->set('tx_semanticsearch_indexed', 1)
            ->set('tx_semanticsearch_last_indexed', time())
            ->executeStatement();
    }

    public function removeFromIndex(int $pageId): void
    {
        $pdo = $this->pgService->getConnection();
        $stmt = $pdo->prepare("DELETE FROM typo3_search_index WHERE typo3_uid = ? AND typo3_table = 'pages'");
        $stmt->execute([$pageId]);
    }

    private function calculateBoostFactor(array $pageRecord, array $content): float
{
    $boostFactor = 1.0; // Default boost
    
    // Boost based on page type
    $doktype = $pageRecord['doktype'] ?? 1;
    switch ($doktype) {
        case 1: // Standard page
            $boostFactor = 1.0;
            break;
        case 4: // Shortcut
            $boostFactor = 0.5;
            break;
        case 199: // Menu separator
            $boostFactor = 0.1;
            break;
    }
    
    // Boost based on page level (higher level = more important)
    $level = $this->getPageLevel($pageRecord);
    if ($level <= 2) {
        $boostFactor *= 1.5; // Top-level pages
    } elseif ($level <= 4) {
        $boostFactor *= 1.2; // Mid-level pages
    }
    
    // Boost based on content length
    $contentLength = strlen($content['content']);
    if ($contentLength > 5000) {
        $boostFactor *= 1.3; // Long content
    } elseif ($contentLength < 500) {
        $boostFactor *= 0.8; // Short content
    }
    
    // Boost based on keywords in title
    $title = strtolower($content['title']);
    $importantKeywords = ['important', 'featured', 'main', 'home'];
    foreach ($importantKeywords as $keyword) {
        if (strpos($title, $keyword) !== false) {
            $boostFactor *= 1.2;
            break;
        }
    }
    
    // Boost based on page properties
    if (!empty($pageRecord['nav_hide'])) {
        $boostFactor *= 0.7; // Hidden from navigation
    }
    
    if (!empty($pageRecord['no_search'])) {
        $boostFactor *= 0.3; // Marked as no search
    }
    
    return round($boostFactor, 2);
}

private function getPageLevel(array $pageRecord): int
{
    // Count the number of parent pages to determine level
    $pid = $pageRecord['pid'];
    $level = 1;
    
    while ($pid > 0) {
        // Query to get parent page
        $parent = $this->connection->select(['pid'], 'pages', ['uid' => $pid])->fetch();
        if ($parent) {
            $pid = $parent['pid'];
            $level++;
        } else {
            break;
        }
    }
    
    return $level;
}
}