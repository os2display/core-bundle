<?php
/**
 * @file
 * Event handlers to send content to the search backend.
 */

namespace Os2Display\CoreBundle\EventListener;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Os2Display\CoreBundle\Services\UtilityService;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializationContext;
use Symfony\Component\DependencyInjection\Container;
use Os2Display\CoreBundle\Entity\GroupableEntity;
use Os2Display\CoreBundle\Entity\Group;

/**
 * Class SearchIndexer
 *
 * @package Os2Display\CoreBundle\EventListener
 */
class SearchIndexer {
  protected $container;
  protected $serializer;
  protected $utilityService;

  /**
   * Constructor
   *
   * @param Serializer $serializer
   * @param Container $container
   * @param UtilityService $utilityService
   */
  public function __construct(Serializer $serializer, Container $container, UtilityService $utilityService) {
    $this->serializer = $serializer;
    $this->container = $container;
    $this->utilityService = $utilityService;
  }

  /**
   * Listen to post persist events.
   *
   * @param LifecycleEventArgs $args
   */
  public function postPersist(LifecycleEventArgs $args) {
    $this->sendEvent($args, 'POST');
  }

  /**
   * Listen to post-update events.
   *
   * @param LifecycleEventArgs $args
   */
  public function postUpdate(LifecycleEventArgs $args) {
    $this->sendEvent($args, 'PUT');
  }

  /**
   * Listen to pre-remove events.
   *
   * @param LifecycleEventArgs $args
   */
  public function preRemove(LifecycleEventArgs $args) {
    $this->sendEvent($args, 'DELETE');
  }

  /**
   * Helper function to send content/command to the search backend..
   *
   * @param LifecycleEventArgs $args
   *   The arguments send to the original event listener.
   * @param $method
   *   The type of operation to preform.
   *
   * @return boolean
   */
  protected function sendEvent(LifecycleEventArgs $args, $method) {
    // Get the current entity.
    $entity = $args->getEntity();

    // Get the actual type of the entity, ensure to handle the situation where
    // we're passed a proxy.
    // Notice, ClassUtils is deprecated, the jury is still out on how to
    // implement the same functionallity in doctrine 3.x though.
    // https://github.com/doctrine/common/issues/867
    $type = ClassUtils::getRealClass(get_class($entity));

    // Only send Channel, Screen, Slide, Media to search engine
    if ($type !== 'Os2Display\CoreBundle\Entity\Channel' &&
      $type !== 'Os2Display\CoreBundle\Entity\Screen' &&
      $type !== 'Os2Display\CoreBundle\Entity\Slide' &&
      $type !== 'Os2Display\MediaBundle\Entity\Media'
    ) {
      return FALSE;
    }

    // Build parameters to send to the search backend.
    $index = $this->container->getParameter('search_index');
    $params = array(
      'index' => $index,
      'type' => $type,
      'id' => $entity->getId(),
      'data' => $entity,
    );

    // Get search backend URL.
    $url = $this->container->getParameter('search_host');
    $path = $this->container->getParameter('search_path');

    if ($entity instanceof GroupableEntity && $groups = $entity->getGroups()) {
      $entity->setGroups($groups->map(function ($group) {
        return isset($group->id) ? $group->id : $group->getid();
      }));
    }

    $data = $this->serializer->serialize($params, 'json', SerializationContext::create()
        ->setGroups(array('search')));

    // Send the request.
    $result = $this->utilityService->curl($url . $path, $method, $data, 'search');

    if ($result['status'] !== 200) {
      // TODO: Handle !
    }

    return $result['status'] === 200;
  }
}
