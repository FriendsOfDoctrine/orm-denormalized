<?php
/**
 *  This file is part of the FOD\OrmDenormalizer package -- Denormalizer ORM Doctrine library
 *
 *  (c) FriendsOfDoctrine <https://github.com/FriendsOfDoctrine/>.
 *
 *  For the full copyright and license inflormation, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace FOD\OrmDenormalizer;

use Doctrine\DBAL\Types\Type;
use FOD\OrmDenormalizer\Mapping\Annotation\Table;
use FOD\OrmDenormalizer\Mapping\DnClassMetadata;
use Doctrine\DBAL\Connection;

/**
 * Class DnTableGroup
 * @package FOD\OrmDenormalizer
 * @author Nikolay Mitrofanov <mitrofanovnk@gmail.com>
 */
class DnTableGroup
{
    /**
     * @var array
     */
    protected $structureSchema = [];

    /** @var array */
    protected $recurrentClasses = [];

    /** @var  string */
    protected $tableName;

    /**
     * @var DnColumn[]
     */
    protected $columns = [];

    /**
     * @var array
     */
    protected $indexes = [];

    /**
     * @var DnClassMetadata[]
     */
    protected $dnClassMetadata = [];
    /**
     * @var bool
     */
    protected $isSetIndex = false;

    /** @var  string */
    protected $eventTimeIndex;

    /** @var DnTableValue[][] */
    protected $dnTableValues = [];

    /** @var array */
    protected $oneToManyRelation = [];

    /**
     * @var array
     */
    protected $columnValuesSetNumbers = [];

    /**
     * DnTableGroup constructor.
     *
     * @param array $structureSchema
     * @param DnClassMetadata[] $dnClassMetadata
     * @param array $oneToManyRelation
     */
    public function __construct(array $structureSchema, array $dnClassMetadata, array $oneToManyRelation)
    {
        $this->structureSchema = $structureSchema;
        $this->dnClassMetadata = $dnClassMetadata;

        foreach (array_reverse($structureSchema) as $schemaKey => $schema) {
            foreach (array_filter($oneToManyRelation, function ($value) use ($schemaKey) {
                return in_array($schemaKey, $value, true);
            }) as $sourceRelation => $relations) {
                foreach ($relations as $relationKey => $relation) {
                    if ($schemaKey === $relation) {
                        $this->oneToManyRelation[$sourceRelation][$relationKey] = $relation;
                    }
                }
            }
        }
    }

    /**
     * @param DnTableValue $value
     *
     * @return $this
     */
    public function addColumnValue(DnTableValue $value)
    {
        $this->columnValuesSetNumbers[$value->getName()] = !isset($this->columnValuesSetNumbers[$value->getName()]) ? 0 : ($this->columnValuesSetNumbers[$value->getName()] + 1);
        $this->dnTableValues[$this->columnValuesSetNumbers[$value->getName()]][$value->getName()] = $value;

        return $this;
    }

    /**
     * @return array
     */
    public function getValuesArray()
    {
        $values = [];
        foreach ($this->dnTableValues as $setIndex => $setValues) {
            foreach ($setValues as $dnTableValue) {
                $values[$setIndex][$dnTableValue->getName()] = $dnTableValue->getValue();
            }
        }

        return $values;
    }

    /**
     * @param string $className
     *
     * @return bool
     */
    public function hasClass($className)
    {
        return count(array_filter($this->structureSchema, function ($value, $key) use ($className) {
                return $key === $className || in_array($className, $value, true);
            }, ARRAY_FILTER_USE_BOTH)) > 0;
    }

    /**
     * @return array
     */
    public function getStructureSchema()
    {
        return $this->structureSchema;
    }

    /**
     * @return array
     */
    public function getOneToManyRelationSchema()
    {
        return $this->oneToManyRelation;
    }

    /**
     * @return string
     */
    public function getTableName()
    {
        if (!$this->tableName) {
            $this->tableName = strtolower(implode(Table::DENORMALIZE_TABLE_DELIMITER, $this->buildTableName()));
        }

        return $this->tableName;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getTableName();
    }

    /**
     * @return DnColumn[]
     */
    public function getColumns()
    {
        if (!$this->columns) {
            $this->buildColumns();
        }

        return $this->columns;
    }

    /**
     * @param $targetEntity
     * @param $targetProperty
     *
     * @return DnColumn
     */
    public function findColumnByTargetEntityAndProperty($targetEntity, $targetProperty)
    {
        return current(array_filter($this->getColumns(), function ($value) use ($targetEntity, $targetProperty) {
            /** @var DnColumn $column */
            return $column->getTargetPropertyName() === $targetProperty && $column->getTargetEntityClass() === $targetEntity;
        }));
    }

    /**
     * @param $targetEntity
     * @param $targetProperty
     *
     * @return null|string
     */
    public function getColumnNameByTargetEntityAndProperty($targetEntity, $targetProperty)
    {
        foreach ($this->getColumns() as $columnName => $column) {
            if ($column->getTargetPropertyName() === $targetProperty && $column->getTargetEntityClass() === $targetEntity) {
                return $columnName;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public function getIndexes()
    {
        return $this->indexes;
    }

    /**
     * @param Connection $connection
     *
     * @return string[]
     */
    public function getMigrationSQL(Connection $connection)
    {
        $fromSchema = $connection->getSchemaManager()->createSchema();

        $toSchema = clone $fromSchema;
        $newTable = $toSchema->createTable($this->getTableName());

        /** @var DnColumn $column */
        foreach ($this->getColumns() as $column) {
            $newTable->addColumn($column->getName(), $column->getType(), $column->getOptions());
        }

        if ($this->getIndexes()) {
            $newTable->setPrimaryKey($this->getIndexes());
        }

        if (null !== $this->eventTimeIndex) {
            $newTable->addOption('eventDateProviderColumn', $this->eventTimeIndex);
        }

        return $fromSchema->getMigrateToSql($toSchema, $connection->getDatabasePlatform());
    }

    /**
     * @param array $columnPrefix
     * @param DnClassMetadata $dnClassMetadata
     *
     * @return $this
     */
    protected function addColumnsOfClassMetadata(array $columnPrefix, DnClassMetadata $dnClassMetadata)
    {
        foreach ($dnClassMetadata->getClassMetadata()->fieldMappings as $fieldName => $field) {
            if (!in_array($fieldName, (array)$dnClassMetadata->getDnTable()->excludeFields, true)) {
                $dnColumn = new DnColumn(
                    implode(Table::DENORMALIZE_FIELD_DELIMITER, array_merge($columnPrefix, [$fieldName])),
                    $field,
                    $dnClassMetadata->getClassMetadata()->name,
                    $fieldName,
                    array_reduce($dnClassMetadata->getClassMetadata()->associationMappings, function ($carry, $value) use ($dnClassMetadata) {
                        $carry = !$carry && $value['targetEntity'] === $dnClassMetadata->getClassMetadata()->name ? $value['fieldName'] : $carry;
                        return $carry;
                    })
                );
                if (!$this->isSetIndex && isset($field['id']) && $field['id']) {
                    $this->indexes[] = $dnColumn->getName();
                }
                if (null === $this->eventTimeIndex && Type::DATETIME === $field['type']) {
                    $this->eventTimeIndex = $dnColumn->getName();
                }
                $this->columns[$dnColumn->getName()] = $dnColumn;
            }
        }

        $this->isSetIndex = true;

        return $this;
    }

    /**
     * @param string $className
     *
     * @return DnClassMetadata|null
     */
    protected function getDnClassMetadataByName($className)
    {
        return isset($this->dnClassMetadata[$className]) ? $this->dnClassMetadata[$className] : null;
    }

    /**
     * @param array $prefix
     * @param string $className
     *
     * @return array
     */
    protected function buildTableName(array $prefix = [], $className = '')
    {
        $tableName = [];
        if (!$className) {
            $className = current(array_keys($this->structureSchema));
            $this->recurrentClasses = [];
        }

        if ($dnClassMetadata = $this->getDnClassMetadataByName($className)) {
            $partTableName = $dnClassMetadata->getDnTable()->name ?: $dnClassMetadata->getClassMetadata()->reflClass->getShortName();
            $tableName[][] = implode(Table::DENORMALIZE_FIELD_DELIMITER, array_merge($prefix, [$partTableName]));

            if (isset($this->structureSchema[$className])) {
                foreach ($this->structureSchema[$className] as $property => $targetClass) {
                    if (!isset($this->recurrentClasses[$targetClass][$property])) {
                        if ($className === $targetClass) {
                            $this->recurrentClasses[$targetClass][$property] = true;
                        }
                        $tableName[] = $this->buildTableName([$property], $targetClass);
                    }
                }
            }
        }

        return $tableName ? call_user_func_array('array_merge', $tableName) : [];
    }

    /**
     * @param array $prefix
     * @param string $className
     * @param string $propertyPrefix
     */
    protected function buildColumns(array $prefix = [], $className = '', $propertyPrefix = '')
    {
        if (!$className) {
            $className = current(array_keys($this->structureSchema));
            $this->recurrentClasses = [];
        }

        if ($dnClassMetadata = $this->getDnClassMetadataByName($className)) {
            $partTableName = $dnClassMetadata->getDnTable()->name ?: $dnClassMetadata->getClassMetadata()->reflClass->getShortName();
            $this->addColumnsOfClassMetadata(array_merge($prefix, $propertyPrefix ? [$propertyPrefix, $partTableName] : [$partTableName]), $dnClassMetadata);
            $prefix[] = $partTableName;

            if (isset($this->structureSchema[$className])) {
                foreach ($this->structureSchema[$className] as $property => $targetClass) {
                    if (!isset($this->recurrentClasses[$targetClass][$property])) {
                        if ($className === $targetClass) {
                            $this->recurrentClasses[$targetClass][$property] = true;
                        }
                        $this->buildColumns($prefix, $targetClass, $property);
                    }
                }
            }
        }
    }
}