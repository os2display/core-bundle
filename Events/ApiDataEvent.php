<?php

namespace Os2Display\CoreBundle\Events;

use Os2Display\CoreBundle\Entity\ApiEntity;
use Symfony\Component\EventDispatcher\Event;

class ApiDataEvent extends Event {
  const API_DATA_ADD = 'os2display.core.api_data.add';

  protected $entity;
  protected $inColletion;

  public function __construct(ApiEntity $entity, $inCollection = false) {
    $this->entity = $entity;
    $this->inColletion = $inCollection;
  }

  public function getEntity() {
    return $this->entity;
  }

  public function isInCollection() {
    return $this->inColletion;
  }
}
