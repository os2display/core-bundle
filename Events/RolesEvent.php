<?php

namespace Os2Display\CoreBundle\Events;

use Symfony\Component\EventDispatcher\Event;

class RolesEvent extends Event {
  const ADD_ROLE_NAMES = 'os2display.core.roles.add_role_names';

  /** @var array */
  protected $roleNames;

  public function getRoleNames() {
    return $this->roleNames;
  }

  public function setRoleNames(array $roleNames) {
    $this->roleNames = $roleNames;
  }

  public function addRoleNames(array $roleNames) {
    $this->roleNames = array_merge($this->roleNames, $roleNames);
  }
}
