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

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SearchService implements SingletonInterface
{
    private PostgreSQLConnectionService $pgService;
    private VectorService $vectorService;

    public function __construct()
    {
        $this->pgService = GeneralUtility::makeInstance(PostgreSQLConnectionService::class);
        $this->vectorService = GeneralUtility::makeInstance(VectorService::class);
    }

    public function search(string $query, int $rootPageId = 0, int $limit = 10): array
    {
        $pdo = $this->pgService->getConnection();
        
        // Generate embedding for search query
        $queryVector = $this->vectorService->generateEmbedding($query);
        $queryVectorString = $this->vectorService->formatVectorForPostgreSQL($queryVector);

        // Semantic search with hybrid scoring
        $sql = "
            SELECT 
                typo3_uid, typo3_table, title, content, url,
                (content_vector <=> :query_vector) AS semantic_distance,
                ts_rank(content_tsvector, plainto_tsquery('english', :query_text)) AS text_rank,
                (
                    (1 - (content_vector <=> :query_vector)) * 0.7 + 
                    ts_rank(content_tsvector, plainto_tsquery('english', :query_text)) * 0.3
                ) AS combined_score
            FROM typo3_search_index
            WHERE 
                (:root_page_id = 0 OR root_page_uid = :root_page_id)
                AND (
                    content_vector <=> :query_vector < 0.5
                    OR content_tsvector @@ plainto_tsquery('english', :query_text)
                )
            ORDER BY combined_score DESC
            LIMIT :limit
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'query_vector' => $queryVectorString,
            'query_text' => $query,
            'root_page_id' => $rootPageId,
            'limit' => $limit,
        ]);

        return $stmt->fetchAll();
    }

    public function getSimilarContent(int $pageId, int $limit = 5): array
    {
        $pdo = $this->pgService->getConnection();
        
        $sql = "
            SELECT 
                s2.typo3_uid, s2.typo3_table, s2.title, s2.content, s2.url,
                (s1.content_vector <=> s2.content_vector) AS similarity
            FROM typo3_search_index s1
            JOIN typo3_search_index s2 ON s1.id != s2.id
            WHERE s1.typo3_uid = :page_id AND s1.typo3_table = 'pages'
            ORDER BY similarity ASC
            LIMIT :limit
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'page_id' => $pageId,
            'limit' => $limit,
        ]);

        return $stmt->fetchAll();
    }
}
