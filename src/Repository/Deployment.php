<?php

/*
 * This file is part of KoolKode BPMN.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\BPMN\Repository;

use KoolKode\BPMN\Engine\ProcessEngine;
use KoolKode\Util\UUID;

class Deployment implements \JsonSerializable
{
	protected $id;
	
	protected $name;
	
	protected $deployDate;
	
	protected $engine;
	
	public function __construct(ProcessEngine $engine, UUID $id, $name, \DateTimeImmutable $deployDate)
	{
		$this->engine = $engine;
		$this->id = $id;
		$this->name = (string)$name;
		$this->deployDate = $deployDate;
	}
	
	public function jsonSerialize()
	{
		return [
			'id' => (string)$this->id,
			'name' => $this->name,
			'deployDate' => $this->deployDate->format(\DateTime::ISO8601)
		];
	}
	
	public function getId()
	{
		return $this->id;
	}
	
	public function getName()
	{
		return $this->name;
	}
	
	public function getDeployDate()
	{
		return $this->deployDate;
	}
	
	public function findResources()
	{
		$resources = [];
		
		$sql = "	SELECT `id`, `name`
					FROM `#__resource`
					WHERE `deployment_id` = :id
					ORDER BY `name`
		";
		$stmt = $this->engine->prepareQuery($sql);
		$stmt->bindValue('id', $this->id);
		$stmt->execute();
		
		while(false !== ($row = $stmt->fetchNextRow()))
		{
			$resources[$row['name']] = new DeployedResource($this, new UUID($row['id']), $row['name']);
		}
		
		return $resources;
	}
}
