<?php

namespace Gedmo\Loggable\Entity\Repository;

use Doctrine\ORM\EntityRepository;
use Gedmo\Loggable\AbstractLoggableListener as Loggable;

/**
 * The LogEntryRepository has some useful functions
 * to interact with log entries.
 * 
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @package Gedmo\Loggable\Entity\Repository
 * @subpackage LogEntryRepository
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class LogEntryRepository extends EntityRepository
{   
    /**
     * Loads all log entries for the
     * given $entity
     * 
     * @param object $entity
     * @return array
     */ 
    public function getLogEntries($entity)
    {
        $objectClass = get_class($entity);
        $objectMeta = $this->_em->getClassMetadata($objectClass);
        $meta = $this->getClassMetadata();
        $dql = "SELECT log FROM {$meta->name} log";
        $dql .= " WHERE log.objectId = :objectId";
        $dql .= " AND log.objectClass = :objectClass";
        $dql .= " ORDER BY log.version DESC";
        
        $identifierField = $objectMeta->getSingleIdentifierFieldName();
        $objectId = $objectMeta->getReflectionProperty($identifierField)->getValue($entity);
        $q = $this->_em->createQuery($dql);
        $q->setParameters(compact('objectId', 'objectClass', 'order'));
        
        return $q->getResult();
    }
    
    /**
     * Reverts given $entity to $revision by
     * restoring all fields from that $revision.
     * After this operation you will need to
     * persist and flush the $entity.
     *
     * @param object $entity
     * @param integer $version
     * @throws \Gedmo\Exception\UnexpectedValueException
     * @return void
     */
    public function revert($entity, $version = 1)
    {
        $objectClass = get_class($entity);
        $objectMeta = $this->_em->getClassMetadata($objectClass);
        $meta = $this->getClassMetadata();
        $dql = "SELECT log FROM {$meta->name} log";
        $dql .= " WHERE log.objectId = :objectId";
        $dql .= " AND log.objectClass = :objectClass";
        $dql .= " AND log.version <= :version";
        $dql .= " ORDER BY log.version ASC";
        
        $identifierField = $objectMeta->getSingleIdentifierFieldName();
        $objectId = $objectMeta->getReflectionProperty($identifierField)->getValue($entity);
        $q = $this->_em->createQuery($dql);
        $q->setParameters(compact('objectId', 'objectClass', 'version'));
        $logs = $q->getResult();
        
        if ($logs) {
            $fields = $objectMeta->fieldNames;
            foreach ($objectMeta->associationMappings as $mapping) {
                if ($objectMeta->isSingleValuedAssociation($mapping['fieldName'])) {
                    $fields[] = $mapping['fieldName'];
                }
            }
            unset($fields[$objectMeta->getSingleIdentifierFieldName()]);
            $filled = false;
            while (($log = array_pop($logs)) && !$filled) {
                if ($data = $log->getData()) {
                    foreach ($data as $field => $value) {
                        if (in_array($field, $fields)) {
                            if ($objectMeta->isSingleValuedAssociation($field)) {
                                $mapping = $objectMeta->getAssociationMapping($field);
                                $value = $this->_em->getReference($mapping['targetEntity'], $value);
                            }
                            $objectMeta->getReflectionProperty($field)->setValue($entity, $value);
                            unset($fields[array_search($field, $fields)]);
                        }
                    }
                }
                $filled = count($fields) === 0;
            }
            if (count($fields)) {
                throw new \Gedmo\Exception\UnexpectedValueException('Cound not fully revert the entity to version: '.$version);
            }
        } else {
            throw new \Gedmo\Exception\UnexpectedValueException('Count not find any log entries under version: '.$version);
        }
    }
}