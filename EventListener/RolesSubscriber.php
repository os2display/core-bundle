<?php

namespace Os2Display\CoreBundle\EventListener;

use Os2Display\CoreBundle\Events\RolesEvent;
use Os2Display\CoreBundle\Security\Roles;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RolesSubscriber implements EventSubscriberInterface {
  public static function getSubscribedEvents() {
    return [RolesEvent::ADD_ROLE_NAMES => 'addRoleNames'];
  }

  public function addRoleNames(RolesEvent $event) {
    $event->addRoleNames(Roles::getRoleNames());
  }
}
