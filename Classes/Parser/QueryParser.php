<?php
namespace ISCOPE\RepairTranslation\Parser;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;

/**
 * QueryParser, converting the qom to string representation
 */
class QueryParser
{

    /**
     * Differs between Join and Select
     *
     * getPreparedQuery and getConstraint of Extbase DataMapper
     * always works with AndConstraints. So, we don't need to check against OR and JOIN
     *
     * @param QueryInterface $query The constraint
     * @param array &$where The where fields
     *
     * @return mixed
     */
    public function parseSource(QueryInterface $query, array &$where) {

        if($query->getSource() instanceof Qom\Join)
        {
            $this->parseJoinConstraint($query->getSource(), $query->getConstraint(), $where);
            return $this->getMMTable($query->getSource());
        }
        elseif ($query->getSource() instanceof Qom\Selector)
        {
            $this->parseSelectConstraint($query->getConstraint(), $where);
            return null;
        }
        return null;
    }

    /**
     * Transforms a constraint into WHERE statements
     *
     * getPreparedQuery and getConstraint of Extbase DataMapper
     * always works with AndConstraints. So, we don't need to check against OR and JOIN
     *
     * @param Qom\JoinInterface $source The source
     * @param Qom\ConstraintInterface $constraint The constraint
     * @param array $where
     *
     * @return void
     */
    public function parseJoinConstraint(Qom\JoinInterface $source= null, Qom\ConstraintInterface $constraint, array &$where) {
        if($constraint instanceof Qom\ComparisonInterface)
        {
            $where[] = sprintf(
                '%s.%s=%s',
                $source->getLeft()->getSelectorName(),
                $constraint->getOperand1()->getPropertyName(),
                $this->getValue($constraint->getOperand2())
            );
        }
    }


    /**
     * Returns MM Table of given source
     *
     * @param Qom\JoinInterface|null $source
     *
     * @return string
     */
    public function getMMTable(Qom\JoinInterface $source= null)
    {
        return $source->getLeft()->getSelectorName();
    }

    /**
     * Transforms a constraint into WHERE statements
     *
     * getPreparedQuery and getConstraint of Extbase DataMapper
     * always works with AndConstraints. So, we don't need to check against OR and JOIN
     *
     * @param Qom\ConstraintInterface $constraint The constraint
     * @param array &$where The where fields
     *
     * @return void
     */
    public function parseSelectConstraint(Qom\ConstraintInterface $constraint = null, array &$where) {
        if ($constraint instanceof Qom\AndInterface) {
            $this->parseSelectConstraint($constraint->getConstraint1(), $where);
            $this->parseSelectConstraint($constraint->getConstraint2(), $where);
        } elseif ($constraint instanceof Qom\OrInterface) {
            $this->parseSelectConstraint($constraint->getConstraint1(), $where);
            $this->parseSelectConstraint($constraint->getConstraint2(), $where);
        } elseif ($constraint instanceof Qom\NotInterface) {
            $this->parseSelectConstraint($constraint->getConstraint(), $where);
        } elseif ($constraint instanceof Qom\ComparisonInterface) {
            $where[] = sprintf(
                '%s.%s=%s',
                $constraint->getOperand1()->getSelectorName(),
                $constraint->getOperand1()->getPropertyName(),
                $this->getValue($constraint->getOperand2())
            );
        }
    }
    
    /**
     * Get Value of operand2
     *
     * @param mixed $operand
     *
     * @return string
     */
    public function getValue($operand)
    {
        if ($operand instanceof AbstractDomainObject) {
            $value = (string)(int)$operand->_getProperty('_localizedUid');
        } elseif (MathUtility::canBeInterpretedAsInteger($operand)) {
            $value = (string)$operand;
        } else {
            $value = $this->getDatabaseConnection()->fullQuoteStr($operand, NULL);
        }
        return $value;
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
}