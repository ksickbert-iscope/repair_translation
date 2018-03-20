<?php
namespace ISCOPE\RepairTranslation\SignalSlot;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2018 Kai Sickbert <ksickbert@iscope.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
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
use Doctrine\Common\Proxy\Exception\UnexpectedValueException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\Comparison;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\ConstraintInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\LogicalAnd;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\LogicalOr;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\SelectorInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;

/**
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class Repair
{
    /**
     * @var \TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected $pageRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Service\EnvironmentService
     * @inject
     */
    protected $environmentService;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper
     * @inject
     */
    protected $dataMapper;

    /**
     * @var \ISCOPE\RepairTranslation\Parser\QueryParser
     * @inject
     */
    protected $queryParser;

    /**
     * Array of relation table names which should be fixed
     *
     * @var array
     */
    protected $tables = [
        // can be filled by tablenames which should be fixed
    ];

    /**
     * Modify sys_file_reference language
     *
     * @param \TYPO3\CMS\Extbase\Persistence\QueryInterface $query
     * @param array $result
     *
     * @return array
     */
    public function modifyRecordLanguages(QueryInterface $query, array $result)
    {
        if ($table = $this->isInTableArray($query)) {
//            $origTranslatedReferences = $this->reduceResultToTranslatedRecords($result);
            $newTranslatedRecords = $this->getNewlyCreatedTranslatedRecords($query, $table);

            if(!empty($newTranslatedRecords))
            {
                $record = current($result);
                if (
                    is_array($record) &&
                    isset($GLOBALS['TCA'][$record['tablenames']]['columns'][$record['fieldname']]['l10n_mode']) &&
                    $GLOBALS['TCA'][$record['tablenames']]['columns'][$record['fieldname']]['l10n_mode'] === 'mergeIfNotBlank'
                ) {
                    // if translation is empty, but mergeIfNotBlank is set, than use the image from default language
                    // keep $result as it is
                } else {
                    // merge with the translated image. If translation is empty $result will be empty, too
                    $result = $newTranslatedRecords;
                }
            }
        }

        return array(
            0 => $query,
            1 => $result
        );
    }

    /**
     * Reduce sysFileReference array to translated records
     *
     * @param array $sysFileReferenceRecords
     *
     * @return array
     */
    protected function reduceResultToTranslatedRecords(array $sysFileReferenceRecords)
    {
        $translatedRecords = array();
        foreach ($sysFileReferenceRecords as $key => $record) {
            if (isset($record['_LOCALIZED_UID'])) {
                // The image reference in translated parent record was not manually deleted.
                // So, l10n_parent is filled and we have a valid translated sys_file_reference record here
                $translatedRecords[] = $record;
            }
        }

        return $translatedRecords;
    }

    /**
     * Check for table array
     *
     * @param QueryInterface $query
     *
     * @return bool
     */
    protected function isInTableArray(QueryInterface $query)
    {
        $source = $query->getSource();
        if ($source instanceof SelectorInterface) {
            $tableName = $source->getSelectorName();
        } elseif ($source instanceof JoinInterface) {
            $tableName = $source->getRight()->getSelectorName();
        } else {
            $tableName = '';
        }

        return in_array($tableName, $this->tables) ? $tableName : false;
    }

    /**
     * Get newly created translated records,
     * which do not have a relation to the default language
     * This will happen, if you translate a record, delete the sys_file_record and create a new one
     *
     * @param QueryInterface $query
     * @param string $table
     * @return array
     */
    protected function getNewlyCreatedTranslatedRecords(QueryInterface $query, string $table)
    {
        $where = array();
        // add where statements. uid_foreign=UID of translated parent record

        $mm_table = $this->queryParser->parseSource($query, $where);

        if ($this->environmentService->isEnvironmentInFrontendMode()) {
            $where[] = ' 1=1 ' . $this->getPageRepository()->enableFields($table);
        } else {
            $where[] = sprintf(
                ' 1=1 %s %s',
                BackendUtility::BEenableFields($table),
                BackendUtility::deleteClause($table)
            );
        }

        if(!$mm_table)
        {
            /* return empty if not mm_relation */
            $rows = $this->getTranslatedRecords($where, $table);
        }
        else {
            $rows = $this->getTranslatedMMRecords($where, $table, $mm_table);
        }

        if (empty($rows)) {
            $rows = array();
        }

        foreach ($rows as $key => &$row) {
            BackendUtility::workspaceOL($table, $row);
            // t3ver_state=2 indicates that the live element must be deleted upon swapping the versions.
            if ((int)$row['t3ver_state'] === 2) {
                unset($rows[$key]);
            }
        }
        return $rows;
    }

    /**
     * get translated Records over mm_table
     *
     * @param array $where
     * @param string $table
     * @param string $mm_table
     *
     * @return array
     */
    protected function getTranslatedMMRecords($where, string $table, string $mm_table)
    {
        /* get related records */
        $rows = $this->getDatabaseConnection()->exec_SELECT_mm_query('*',
            '',
            $mm_table,
            $table,
            'AND ' . implode(' AND', $where),
            '',
            ''
        );

        /* get language overlay for related records */
        $results = array();
        while($row = $rows->fetch_assoc())
        {
            $results[] = $this->getPageRepository()->getRecordOverlay($table, $row, $this->getSysLanguage());
        }

        return $results;
    }

    /**
     * @param array  $where
     * @param string $table
     *
     * @return array|null
     */
    protected function getTranslatedRecords(array $where, string $table)
    {
        $rows = $this->getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            $table,
            implode(' AND ', $where),
            '',
            'sorting_foreign ASC'
        );

        return $rows;
    }

    /**
     * Merges array with translation records with fallback if something isn't translated
     *
     * @param $oldRecords
     * @param $newRecords
     */
    protected function mergeUntranslatedRecords($oldRecords, &$newRecords)
    {
        foreach ($newRecords as $newRecord)
        {
            foreach ($oldRecords as $oldRecord)
            {
                if($newRecord['l10n_parent'] != $oldRecord['uid'])
                {
                    $newRecords[] = $oldRecord;
                }
            }
        }
    }

    /**
     * Get page repository
     *
     * @return \TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected function getPageRepository() {
        if (!$this->pageRepository instanceof \TYPO3\CMS\Frontend\Page\PageRepository) {
            if ($this->environmentService->isEnvironmentInFrontendMode() && is_object($GLOBALS['TSFE'])) {
                $this->pageRepository = $GLOBALS['TSFE']->sys_page;
            } else {
                $this->pageRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
            }
        }

        return $this->pageRepository;
    }

    /**
     * Get TYPO3s Database Connection
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    protected function getSysLanguage()
    {
        return $GLOBALS['TSFE']->sys_language_uid;
    }
}
