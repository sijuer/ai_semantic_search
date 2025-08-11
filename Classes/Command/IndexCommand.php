<?php
declare(strict_types=1);

namespace PITS\AiSemanticSearch\Command;

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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use PITS\AiSemanticSearch\Service\IndexService;
use PITS\AiSemanticSearch\Service\PostgreSQLConnectionService;

class IndexCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Index pages for semantic search')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform: index, reindex, clear'
            )
            ->addOption(
                'page',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Specific page ID to index'
            )
            ->addOption(
                'root-page',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Root page ID to index (includes all subpages)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $pageId = $input->getOption('page');
        $rootPageId = $input->getOption('root-page');

        $indexService = GeneralUtility::makeInstance(IndexService::class);
        $pgService = GeneralUtility::makeInstance(PostgreSQLConnectionService::class);

        switch ($action) {
            case 'index':
                $this->indexPages($io, $indexService, $pageId, $rootPageId);
                break;
            case 'reindex':
                $this->reindexPages($io, $indexService, $pageId, $rootPageId);
                break;
            case 'clear':
                $this->clearIndex($io, $pgService);
                break;
            case 'setup':
                $this->setupDatabase($io, $pgService);
                break;
            default:
                $io->error("Unknown action: {$action}");
                return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function indexPages(SymfonyStyle $io, IndexService $indexService, ?string $pageId, ?string $rootPageId): void
    {
        if ($pageId) {
            $io->text("Indexing page ID: {$pageId}");
            $indexService->indexPage((int)$pageId);
            $io->success("Page indexed successfully");
            return;
        }

        $pages = $this->getPages($rootPageId ? (int)$rootPageId : null);
        $io->progressStart(count($pages));

        foreach ($pages as $page) {
            try {
                $indexService->indexPage($page['uid']);
                $io->progressAdvance();
            } catch (\Exception $e) {
                $io->warning("Failed to index page {$page['uid']}: " . $e->getMessage());
            }
        }

        $io->progressFinish();
        $io->success(sprintf('Indexed %d pages', count($pages)));
    }

    private function reindexPages(SymfonyStyle $io, IndexService $indexService, ?string $pageId, ?string $rootPageId): void
    {
        $io->text('Reindexing pages (this will update existing entries)');
        $this->indexPages($io, $indexService, $pageId, $rootPageId);
    }

    private function clearIndex(SymfonyStyle $io, PostgreSQLConnectionService $pgService): void
    {
        $io->text('Clearing search index...');
        $pdo = $pgService->getConnection();
        $pdo->exec('TRUNCATE TABLE typo3_search_index');
        $io->success('Search index cleared');
    }

    private function setupDatabase(SymfonyStyle $io, PostgreSQLConnectionService $pgService): void
    {
        $io->text('Setting up PostgreSQL search database...');
        $pgService->createSearchTables();
        $io->success('Database setup completed');
    }

    private function getPages(?int $rootPageId = null): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');

        $query = $queryBuilder
            ->select('uid', 'title', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('hidden', 0),
                $queryBuilder->expr()->in('doktype', [
                    1,   // Standard page
                ])
            );

        if ($rootPageId) {
            // Get all pages under the root page
            $query->andWhere(
                $queryBuilder->expr()->like('pid', $queryBuilder->createNamedParameter($rootPageId . '%'))
            );
        }

        return $query->executeQuery()->fetchAllAssociative();
    }
}