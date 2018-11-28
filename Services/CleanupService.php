<?php

namespace Os2Display\CoreBundle\Services;

use Doctrine\ORM\EntityManagerInterface;
use Os2Display\CoreBundle\Events\CleanupEvent;
use Os2Display\MediaBundle\Entity\Media;
use Os2Display\CoreBundle\Entity\Slide;
use Os2Display\CoreBundle\Entity\Channel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class CleanupService
 *
 * @package Os2Display\CoreBundle\Services
 */
class CleanupService {
  protected $entityManager;
  protected $mediaRepository;
  protected $dispatcher;

  /**
   * CleanupService constructor.
   */
  public function __construct(EntityManagerInterface $entityManager, EventDispatcherInterface $dispatcher) {
    $this->entityManager = $entityManager;
    $this->dispatcher = $dispatcher;
    $this->mediaRepository = $entityManager->getRepository(Media::class);
  }

  /**
   * Find unused media with updatedAt lower than threshold timestamp, if set.
   *
   * @param int $threshold Optional threshold. If set all items with modification
   *                       date before threshold will be included.
   * @return mixed Media.
   */
  public function findMediaToDelete($threshold = null) {
    $qb = $this->entityManager->createQueryBuilder();

    $query = $qb->select('entity')
      ->from(Media::class, 'entity')
      ->where('entity.mediaOrders is empty')
      ->andWhere('entity.logoSlides is empty');

    if (!is_null($threshold)) {
      $query->andWhere('entity.updatedAt < :threshold')
        ->setParameter('threshold', \DateTime::createFromFormat('U', $threshold));
    }

    return $query->getQuery()->getResult();
  }

  /**
   * Find unused slides and modifiedAt lower than threshold timestamp, if set.
   *
   * @param int $threshold Optional threshold. If set all items with modification
   *                       date before threshold will be included.
   * @return mixed Slide.
   */
  public function findSlidesToDelete($threshold = null) {
    $qb = $this->entityManager->createQueryBuilder();

    $query = $qb->select('entity')
      ->from(Slide::class, 'entity')
      ->where('entity.channelSlideOrders is empty');

    if (!is_null($threshold)) {
      $query->andWhere('entity.modifiedAt < :threshold')
        ->setParameter('threshold', $threshold);
    }

    return $query->getQuery()->getResult();
  }

  /**
   * Find unused channels and modifiedAt lower than threshold timestamp, if set.
   *
   * @param int $threshold Optional threshold. If set all items with modification
   *                       date before threshold will be included.
   * @return mixed Channel.
   */
  public function findChannelsToDelete($threshold = null) {
    $qb = $this->entityManager->createQueryBuilder();

    $query = $qb->select('entity')
      ->from(Channel::class, 'entity')
      ->where('entity.channelScreenRegions is empty');

    if (!is_null($threshold)) {
      $query->andWhere('entity.modifiedAt < :threshold')
        ->setParameter('threshold', $threshold);
    }

    $results = $query->getQuery()->getResult();

    // Emit event to other bundles able to protect channels that should not be deleted.
    $event = new CleanupEvent($results);
    $event = $this->dispatcher->dispatch(CleanupEvent::EVENT_CLEANUP_CHANNELS, $event);
    $results = $event->getEntities();

    return $results;
  }

  public function deleteEntity($entity) {
    $this->entityManager->remove($entity);
    $this->entityManager->flush();

    return true;
  }
}
