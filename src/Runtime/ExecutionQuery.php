<?php

/*
 * This file is part of KoolKode BPMN.
*
* (c) Martin Schröder <m.schroeder2007@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace KoolKode\BPMN\Runtime;

use KoolKode\BPMN\Engine\AbstractQuery;
use KoolKode\BPMN\Engine\BinaryData;
use KoolKode\BPMN\Engine\ProcessEngine;
use KoolKode\BPMN\Repository\ProcessDefinition;
use KoolKode\Util\UnicodeString;
use KoolKode\Util\UUID;

/**
 * Queries for persisted executions.
 * 
 * @author Martin Schröder
 */
class ExecutionQuery extends AbstractQuery
{
	use VariableQueryTrait;
	
	protected $processInstanceId;
	protected $executionId;
	protected $parentId;
	protected $activityId;
	protected $processBusinessKey;
	protected $processDefinitionKey;
	
	protected $signalEventSubscriptionNames = [];
	protected $messageEventSubscriptionNames = [];
	
	protected $queryProcess;
	protected $engine;
	
	public function __construct(ProcessEngine $engine, $queryProcess = false)
	{
		$this->engine = $engine;
		$this->queryProcess = $queryProcess ? true : false;
	}
	
	public function processInstanceId($id)
	{
		$this->populateMultiProperty($this->processInstanceId, $id, function($value) {
			return new UUID($value);
		});
		
		return $this;
	}
	
	public function executionId($id)
	{
		$this->populateMultiProperty($this->executionId, $id, function($value) {
			return new UUID($value);
		});
		
		return $this;
	}
	
	public function parentId($id)
	{
		$this->populateMultiProperty($this->parentId, $id, function($value) {
			return new UUID($value);
		});
		
		return $this;
	}
	
	public function activityId($id)
	{
		$this->populateMultiProperty($this->activityId, $id);
		
		return $this;
	}
	
	public function processBusinessKey($key)
	{
		$this->populateMultiProperty($this->processBusinessKey, $key);
		
		return $this;
	}
	
	public function processDefinitionKey($key)
	{
		$this->populateMultiProperty($this->processDefinitionKey, $key);
		
		return $this;
	}
	
	public function signalEventSubscriptionName($signalName)
	{
		$this->signalEventSubscriptionNames[] = (string)$signalName;
		
		return $this;
	}
	
	public function messageEventSubscriptionName($messageName)
	{
		$this->messageEventSubscriptionNames[] = (string)$messageName;
		
		return $this;
	}
	
	public function count()
	{
		$stmt = $this->executeSql(true);
		
		return (int)$stmt->fetchNextColumn(0);
	}
	
	public function findOne()
	{
		$stmt = $this->executeSql(false, 1);
		$row = $stmt->fetchNextRow();
		
		if($row === false)
		{
			throw new \OutOfBoundsException(sprintf('No matching execution found'));
		}
		
		return $this->unserializeExecution($row);
	}
	
	public function findAll()
	{
		$stmt = $this->executeSql();
		$result = [];
		
		while($row = $stmt->fetchNextRow())
		{
			$result[] = $this->unserializeExecution($row);
		}
		
		return $result;
	}
	
	protected function unserializeExecution(array $row)
	{
		$def = new ProcessDefinition(
			new UUID($row['def_id']),
			$row['def_key'],
			$row['def_rev'],
			unserialize(BinaryData::decode($row['def_data'])),
			$row['def_name'],
			new \DateTimeImmutable('@' . $row['def_deployed']),
			empty($row['deployment_id']) ? NULL : new UUID($row['deployment_id'])
		);
		
		return new Execution(
			$def,
			new UUID($row['id']),
			new UUID($row['process_id']),
			empty($row['pid']) ? NULL : new UUID($row['pid']),
			$row['node'],
			(int)$row['state'] & \KoolKode\Process\Execution::STATE_TERMINATE,
			$row['business_key']
		);
	}
	
	protected function executeSql($count = false, $limit = 0, $offset = 0)
	{
		if($count)
		{
			$fields = 'COUNT(*) AS num';
		}
		else
		{
			$fields = '	e.*,
						d.`id` AS def_id,
						d.`deployment_id`,
						d.`process_key` AS def_key,
						d.`revision` AS def_rev,
						d.`definition` AS def_data,
						d.`name` AS def_name,
						d.`deployed_at` AS def_deployed
			';
		}
		
		$sql = "	SELECT $fields
					FROM `#__execution` AS e
					INNER JOIN `#__process_definition` AS d ON (d.`id` = e.`definition_id`)
		";
		
		$alias = 1;
		$joins = [];
		$where = [];
		$params = [];
		
		if($this->queryProcess)
		{
			$where[] = 'e.`id` = e.`process_id`';
		}
		
		$this->buildPredicate("e.`id`", $this->executionId, $where, $params);
		$this->buildPredicate("e.`process_id`", $this->processInstanceId, $where, $params);
		$this->buildPredicate("e.`pid`", $this->parentId, $where, $params);
		$this->buildPredicate("e.`node`", $this->activityId, $where, $params);
		$this->buildPredicate("e.`business_key`", $this->processBusinessKey, $where, $params);
		$this->buildPredicate("d.`process_key`", $this->processDefinitionKey, $where, $params);
		
		foreach($this->variableValues as $var)
		{
			$joins[] = 'INNER JOIN `#__execution_variables` AS v' . $alias . " ON (v$alias.`execution_id` = e.`id`)";
			
			$p1 = 'p' . count($params);
			$p2 = 'p' . (count($params) + 1);
			
			$where[] = "v$alias.`name` = :$p1";
			$params[$p1] = $var->getName();
			
			$val = $var->getValue();
			$field = 'value';
			
			if(is_bool($val))
			{
				$val = $val ? '1' : '0';
			}
			else
			{
				$val = (new UnicodeString($val))->toLowerCase();
				
				if($val->length() > 250)
				{
					if(!in_array($var->getOperator(), ['=', '<>']))
					{
						throw new \RuntimeException(sprintf('Large variable values (more than 250 characters) only support "=" and "<>" operators'));
					}
					
					$val = new BinaryData(serialize($val));
					$field = 'value_blob';
				}
			}
			
			$where[] = "v$alias.`$field` " . $var->getOperator() . " :$p2";
			$params[$p2] = $val;
			
			$alias++;
		}
		
		foreach($this->signalEventSubscriptionNames as $name)
		{
			$joins[] = 'INNER JOIN `#__event_subscription` AS s' . $alias . " ON (s$alias.`execution_id` = e.`id`)";
			
			$p1 = 'p' . count($params);
			$p2 = 'p' . (count($params) + 1);
			
			$where[] = "s$alias.`flags` = :$p1";
			$params[$p1] = ProcessEngine::SUB_FLAG_SIGNAL;
			
			$where[] = "s$alias.`name` = :$p2";
			$params[$p2] = $name;
			
			$alias++;
		}
		
		foreach($this->messageEventSubscriptionNames as $name)
		{
			$joins[] = 'INNER JOIN `#__event_subscription` AS s' . $alias . " ON (s$alias.`execution_id` = e.`id`)";
			
			$p1 = 'p' . count($params);
			$p2 = 'p' . (count($params) + 1);
			
			$where[] = "s$alias.`flags` = :$p1";
			$params[$p1] = ProcessEngine::SUB_FLAG_MESSAGE;
				
			$where[] = "s$alias.`name` = :$p2";
			$params[$p2] = $name;
				
			$alias++;
		}
		
		foreach($joins as $join)
		{
			$sql .= ' ' . $join;
		}
		
		if(!empty($where))
		{
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}
		
		$stmt = $this->engine->prepareQuery($sql);
		$stmt->bindAll($params);
		$stmt->setLimit($limit);
		$stmt->setOffset($offset);
		$stmt->execute();
		
		return $stmt;
	}
}
