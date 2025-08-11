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

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PostgreSQLConnectionService implements SingletonInterface
{
    private $connection;
    private array $config;

    public function __construct()
    {
        $this->config = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('ai_semantic_search');
        $this->initializeConnection();
    }

    private function initializeConnection(): void
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->config['postgresql_host'] ?? 'localhost',
            $this->config['postgresql_port'] ?? 5432,
            $this->config['postgresql_database'] ?? 'typo3_search'
        );

        $this->connection = new \PDO(
            $dsn,
            $this->config['postgresql_user'] ?? 'postgres',
            $this->config['postgresql_password'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );

        // Ensure pgvector extension is loaded
        $this->connection->exec('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function getConnection(): \PDO
    {
        return $this->connection;
    }

    public function createSearchTables(): void
    {
        $vectorService = GeneralUtility::makeInstance(VectorService::class);
        $dimension = $vectorService->getEmbeddingDimension();

        $sql = "
            CREATE TABLE IF NOT EXISTS typo3_search_index (
                id SERIAL PRIMARY KEY,
                typo3_uid INTEGER NOT NULL,
                typo3_table VARCHAR(255) NOT NULL,
                typo3_pid INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                url VARCHAR(500),
                language_uid INTEGER DEFAULT 0,
                root_page_uid INTEGER NOT NULL,
                content_hash VARCHAR(32) NOT NULL,
                boost_factor DECIMAL(3,2) DEFAULT 1.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                content_vector vector({$dimension}),
                title_vector vector({$dimension}),
                content_tsvector tsvector,
                UNIQUE(typo3_uid, typo3_table, language_uid)
            );

            -- Create indexes for vector similarity search
            CREATE INDEX IF NOT EXISTS idx_typo3_search_content_vector ON typo3_search_index 
            USING ivfflat (content_vector vector_cosine_ops) WITH (lists = 100);

            CREATE INDEX IF NOT EXISTS idx_typo3_search_title_vector ON typo3_search_index 
            USING ivfflat (title_vector vector_cosine_ops) WITH (lists = 100);

            -- Create indexes for full-text search
            CREATE INDEX IF NOT EXISTS idx_typo3_search_content_tsvector ON typo3_search_index 
            USING gin(content_tsvector);

            -- Create indexes for filtering
            CREATE INDEX IF NOT EXISTS idx_typo3_search_root_page ON typo3_search_index (root_page_uid);
            CREATE INDEX IF NOT EXISTS idx_typo3_search_language ON typo3_search_index (language_uid);
            CREATE INDEX IF NOT EXISTS idx_typo3_search_table ON typo3_search_index (typo3_table);
            CREATE INDEX IF NOT EXISTS idx_typo3_search_hash ON typo3_search_index (content_hash);

            -- Create function for automatic tsvector updates
            CREATE OR REPLACE FUNCTION update_content_tsvector() RETURNS trigger AS \$\$
            BEGIN
                NEW.content_tsvector := to_tsvector('english', NEW.title || ' ' || NEW.content);
                NEW.updated_at := CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            -- Create trigger for automatic tsvector updates
            DROP TRIGGER IF EXISTS tsvector_update_trigger ON typo3_search_index;
            CREATE TRIGGER tsvector_update_trigger 
                BEFORE INSERT OR UPDATE ON typo3_search_index 
                FOR EACH ROW EXECUTE FUNCTION update_content_tsvector();

            -- Create table for search analytics
            CREATE TABLE IF NOT EXISTS typo3_search_analytics (
                id SERIAL PRIMARY KEY,
                query_text TEXT NOT NULL,
                query_vector vector({$dimension}),
                root_page_uid INTEGER NOT NULL,
                language_uid INTEGER DEFAULT 0,
                results_count INTEGER DEFAULT 0,
                search_time_ms INTEGER DEFAULT 0,
                user_ip INET,
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_search_analytics_query_vector ON typo3_search_analytics 
            USING ivfflat (query_vector vector_cosine_ops) WITH (lists = 50);

            CREATE INDEX IF NOT EXISTS idx_search_analytics_root_page ON typo3_search_analytics (root_page_uid);
            CREATE INDEX IF NOT EXISTS idx_search_analytics_created_at ON typo3_search_analytics (created_at);
        ";

        $this->connection->exec($sql);
    }
}
