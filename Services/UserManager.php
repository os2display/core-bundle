<?php
/**
 * @file
 * Contains the user manager.
 */

namespace Os2Display\CoreBundle\Services;

use FOS\UserBundle\Doctrine\UserManager as FOSUserManager;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use Os2Display\CoreBundle\Entity\User;
use Os2Display\CoreBundle\Exception\DuplicateEntityException;
use Os2Display\CoreBundle\Exception\ValidationException;

/**
 * Class UserManager
 *
 * @package Os2Display\CoreBundle\Services
 */
class UserManager {

  protected static $editableProperties = [
    'email',
    'firstname',
    'lastname',
    'roles',
  ];

  protected $userManager;

  protected $mailerService;

  protected $entityService;

  protected $tokenGenerator;

  protected $securityMananager;

  /**
   * UserManager constructor.
   *
   * @param \FOS\UserBundle\Doctrine\UserManager $userManager
   * @param \Os2Display\CoreBundle\Services\UserMailerService $mailerService
   */
  public function __construct(
    FOSUserManager $userManager,
    UserMailerService $mailerService,
    EntityService $entityService,
    TokenGeneratorInterface $tokenGenerator,
    SecurityManager $securityManager
  ) {
    $this->userManager = $userManager;
    $this->mailerService = $mailerService;
    $this->entityService = $entityService;
    $this->tokenGenerator = $tokenGenerator;
    $this->securityMananager = $securityManager;
  }

  /**
   * Create a user.
   *
   * @param $data
   *
   * @return \FOS\UserBundle\Model\UserInterface
   * @throws \Os2Display\CoreBundle\Exception\DuplicateEntityException
   */
  public function createUser($data) {
    // Create user object.
    $user = $this->userManager->createUser();

    $data = $this->normalizeData($data);
    $this->entityService->setValues($user, $data, self::$editableProperties);

    $user->setUsername($user->getEmail());
    $user->setPlainPassword(uniqid());
    $user->setEnabled(TRUE);

    $this->entityService->validateEntity($user);

    if ($this->userManager->findUserByEmail($user->getEmail())) {
      throw new DuplicateEntityException('User already exists.', $data);
    }

    // Send confirmation email.
    if (NULL === $user->getConfirmationToken()) {
      $user->setConfirmationToken($this->tokenGenerator->generateToken());
    }
    $this->mailerService->sendUserCreatedEmailMessage($user);
    $user->setPasswordRequestedAt(new \DateTime());

    $this->userManager->updateUser($user);

    return $user;
  }

  /**
   * Update a user.
   *
   * @param \Os2Display\CoreBundle\Entity\User $user
   * @param $data
   *
   * @return \FOS\UserBundle\Model\UserInterface
   * @throws \Os2Display\CoreBundle\Exception\DuplicateEntityException
   */
  public function updateUser(User $user, $data) {
    $data = $this->normalizeData($data);

    $this->authorizeUpdate($user, $data);

    $this->entityService->setValues($user, $data, self::$editableProperties);

    $this->entityService->validateEntity($user);

    $anotherUser = $this->userManager->findUserByEmail($user->getEmail());
    if ($anotherUser && $anotherUser->getId() !== $user->getId()) {
      throw new DuplicateEntityException('User already exists.', $data);
    }

    $this->userManager->updateUser($user);

    return $user;
  }

  private function normalizeData(array $data) {
    if (isset($data['roles'])) {
      if ($this->isAssoc($data['roles'])) {
        $data['roles'] = array_keys($data['roles']);
      }
    }

    return $data;
  }

  private function isAssoc(array $arr) {
    if ([] === $arr) {
      return FALSE;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
  }


  /**
   * Verifies that the user has permissions to do the update.
   *
   * @param \Os2Display\CoreBundle\Entity\User $user
   *   The user we're about to mutate.
   * @param array $data
   *   The data we want to change on the user.
   *
   * @throws \Os2Display\CoreBundle\Exception\ValidationException
   */
  private function authorizeUpdate(User $user, $data) {
    // Determine the users current roles and the purposed change.
    $current_roles = $user->getRoles();
    $assigning_roles = isset($data['roles']) ? $data['roles'] : [];

    // Check that the two role arrays contains the same values and keys
    // (regardless of order).
    $isChangingRoles = ($current_roles != $assigning_roles);

    // Determine if we're attempting to modify roles, if so, require the current
    // user to be authorized to do so.
    if ($isChangingRoles && !$this->securityMananager->canAssignRoles($assigning_roles)) {
      throw new ValidationException('Not autorized to assign roles', $assigning_roles);
    }
  }
}
