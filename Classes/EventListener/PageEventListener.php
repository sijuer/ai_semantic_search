<?php
declare(strict_types=1);

namespace PITS\AiSemanticSearch\EventListener;

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

use TYPO3\CMS\Core\DataHandling\Event\AfterRecordUpdatedEvent;
use TYPO3\CMS\Core\DataHandling\Event\RecordDeletedEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use PITS\AiSemanticSearch\Service\IndexService;

class PageEventListener
{
    public function __invoke(AfterRecordUpdatedEvent $event): void
    {
        if ($event->getTable() !== 'pages') {
            return;
        }

        $indexService = GeneralUtility::makeInstance(IndexService::class);
        $indexService->indexPage((int)$event->getRecordUid());
    }

    public function handlePageDeletion(RecordDeletedEvent $event): void
    {
        if ($event->getTable() !== 'pages') {
            return;
        }

        $indexService = GeneralUtility::makeInstance(IndexService::class);
        $indexService->removeFromIndex((int)$event->getRecordUid());
    }
}
