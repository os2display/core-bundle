<?php
/**
 * @file
 * Contains the entity service.
 */

namespace Os2Display\CoreBundle\Services;

use Doctrine\Common\Persistence\ObjectManager;
use Os2Display\CoreBundle\Entity\Channel;
use Os2Display\CoreBundle\Entity\ChannelSlideOrder;
use Os2Display\CoreBundle\Entity\Slide;
use Os2Display\CoreBundle\Exception\ValidationException;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Class EntityService
 *
 * @package Os2Display\CoreBundle\Services
 */
class EntityService {
  private $accessor;
  private $validator;

  /**
   * @var \Symfony\Component\Translation\TranslatorInterface
   */
  protected $translator;

  /**
   * @var \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
   */
  protected $tokenStorage;

  private $entityManager;

  /**
   * EntityService constructor.
   *
   * @param ValidatorInterface $validator
   * @param \Doctrine\Common\Persistence\ObjectManager $entityManager
   * @param \Symfony\Component\Translation\TranslatorInterface $translator
   * @param \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $tokenStorage
   */
  public function __construct(ValidatorInterface $validator, ObjectManager $entityManager, TranslatorInterface $translator, TokenStorageInterface $tokenStorage) {
    $this->accessor = PropertyAccess::createPropertyAccessor();
    $this->validator = $validator;
    $this->entityManager = $entityManager;
    $this->translator = $translator;
    $this->tokenStorage = $tokenStorage;
  }

  /**
   * Set values on entity.
   *
   * @param $entity
   *   The entity to set values on.
   * @param array $values
   *   The values to set, in [name => value] array.
   * @param array|NULL $properties
   *   The names to set.
   * @return \Doctrine\ORM\Mapping\Entity
   *   The entity.
   */
  public function setValues($entity, $values, array $properties = NULL) {
    foreach ($values as $name => $value) {
      if ($properties === NULL || in_array($name, $properties)) {
        if ($this->accessor->isWritable($entity, $name)) {
          $this->accessor->setValue($entity, $name, $value);
        }
      }
    }

    return $entity;
  }

  /**
   * Validate an entity.
   *
   * @param $entity
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   * @throws \Os2Display\CoreBundle\Exception\ValidationException
   */
  public function validateEntity($entity) {
    $errors = $this->validator->validate($entity);

    if (count($errors) > 0) {
      throw new ValidationException('Validation exceptions', $errors);
    }

    return $errors;
  }

    /**
     * Clones a slide.
     *
     * @param \Os2Display\CoreBundle\Entity\Slide $slide
     *
     * @param bool $flush
     *   Whether to flush the entitymanager after creation. Set this to false
     *   if you need to do futher work with the entity before it is fully
     *   persisted.
     *
     * @return \Os2Display\CoreBundle\Entity\Slide
     */
  public function cloneSlide(Slide $slide, $flush = true) {
      $slideClone = clone $slide;

      $slideClone->setTitle(
        $this->translator->trans(
          'administration.clone.cloned_slide_title',
          ['%original_title%' => $slide->getTitle()],
          'Os2DisplayCoreBundle'
        )
      );

      // Treat this a s new slide.
      $slideClone->setCreatedAt(time());

      // Set creator.
      $userEntity = $this->tokenStorage->getToken()->getUser();
      $slideClone->setUser($userEntity->getId());

      $this->entityManager->persist($slideClone);

      if ($flush) {
          $this->entityManager->flush();
      }

      return $slideClone;
  }

  /**
   * Clones a channel.
   *
   * @param \Os2Display\CoreBundle\Entity\Slide $slide
   *
   * @return \Os2Display\CoreBundle\Entity\Channel
   */
  public function cloneChannel(Channel $channel) {
      // Start doing a semi-deep clone of the channel. We want a clone of the
      // channel, and a clone of all slides in the channel.
      // The slides are associated via a "ChannelOrder", so we need a clone of
      // that entity as well as the slide it references.
      $channelClone = clone $channel;
      $channelClone->setTitle($channel->getTitle() . ' (klon)');

      $channelClone->setTitle(
        $this->translator->trans(
          'administration.clone.cloned_channel_title',
          ['%original_title%' => $channel->getTitle()],
          'Os2DisplayCoreBundle'
        )
      );

      /** @var ChannelSlideOrder[] $slideOrders */
      $slideOrders = $channel->getChannelSlideOrders();
      foreach ($slideOrders as $slideOrder) {
          $orderClone = clone $slideOrder;
          $orderClone->setChannel($channelClone);

          $slide = $orderClone->getSlide();

          if (NULL !== $slide) {
              // $slideClone = $this->cloneSlide($slide, false);

              // We don't use cascading persists, so each cloned entity must be
              // persisted before it can be passed to another entity.
              $this->entityManager->persist($slide);
              $orderClone->setSlide($slide);

              $this->entityManager->persist($orderClone);
              $channelClone->addChannelSlideOrder($orderClone);
          }
      }
      $this->entityManager->persist($channelClone);

      $this->entityManager->flush();

      return $channelClone;
  }
}
