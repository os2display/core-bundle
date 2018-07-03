<?php

namespace Os2Display\CoreBundle\Features\Context;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Behat\Symfony2Extension\Context\KernelAwareContext;
use Behatch\Context\BaseContext;
use Behatch\HttpCall\HttpCallResultPool;
use Behatch\HttpCall\Request;
use Behatch\Json\Json;
use Behatch\Json\JsonInspector;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\SchemaTool;
use Os2Display\CoreBundle\Entity\User;
use Os2Display\CoreBundle\Entity\UserGroup;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Defines application features from the specific context.
 */
class FeatureContext extends BaseContext implements Context, KernelAwareContext
{
    private $kernel;
    private $container;

    /**
     * @var ManagerRegistry
     */
    private $doctrine;
    /**
     * @var \Doctrine\Common\Persistence\ObjectManager
     */
    private $manager;

    /**
     * @var \Behatch\HttpCall\Request
     */
    private $request;

    /**
     * @var \Behatch\Json\JsonInspector
     */
    private $inspector;

    /**
     * @var \Behatch\HttpCall\HttpCallResultPool
     */
    private $httpCallResultPool;

    /**
     * Initializes context.
     *
     * Every scenario gets its own context instance.
     * You can also pass arbitrary arguments to the
     * context constructor through behat.yml.
     *
     * @param mixed $evaluationMode
     */
    public function __construct(
        ManagerRegistry $doctrine,
        Request $request,
        HttpCallResultPool $httpCallResultPool,
        $evaluationMode = 'javascript'
    ) {
        $this->doctrine = $doctrine;
        $this->request = $request;
        $this->manager = $doctrine->getManager();
        $this->schemaTool = new SchemaTool($this->manager);
        $this->classes = $this->manager->getMetadataFactory()->getAllMetadata();

        $this->inspector = new JsonInspector($evaluationMode);
        $this->httpCallResultPool = $httpCallResultPool;
    }

    public function setKernel(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
        $this->container = $this->kernel->getContainer();
    }

    /**
     * Checks, that a given element contains the specified text.
     * Example: Then I should see an "h1" element containing "My page"
     * Example: Then I see "My page" in "h1".
     *
     * @Then /^(?:|I )(?:should )?see (?:an? )?"(?P<selector>[^"]+)" (?:element)? containing "(?P<text>[^"]+)"$/
     * @Then /^(?:|I )see "(?P<text>[^"]*)" in (?:an?)? "(?P<selector>[^"]+)"(?: element)?$/
     *
     * @param mixed $selector
     * @param mixed $text
     */
    public function iShouldSeeAnElementContaining($selector, $text)
    {
        $this->assertSession()->elementTextContains('css', $selector, $text);
    }

    /**
     * Wait for a number of seconds.
     *
     * @When /^(?:|I )wait (?:for )?(?P<value>[0-9]+) seconds?$/
     *
     * @param mixed $value
     */
    public function iWaitForSeconds($value)
    {
        $this->getSession()->wait(1000 * $value);
    }

    /**
     * Click on an element.
     *
     * @When /^(?:|I )click (?:a |the )?"(?P<selector>[^"]+)"(?: element)?$/
     *
     * @param mixed $selector
     */
    public function iClickTheElement($selector)
    {
        $session = $this->getSession();
        $page = $session->getPage();
        $element = $page->find('css', $selector);
        if ($element === null) {
            try {
                $element = $page->find('named', ['content', $selector]);
            } catch (\Exception $e) {
            }
        }
        if (null === $element) {
            throw new \InvalidArgumentException(sprintf('Could not find element matching selector "%s"', $selector));
        }

        $element->click();
    }

    /**
     * {@inheritdoc}
     */
    public function fillField($field, $value)
    {
        // See if we can find the field by css selector.
        $element = $this->getSession()->getPage()->find('css', $field);
        if ($element !== null) {
            $element->setValue($value);
        } else {
            parent::fillField($field, $value);
        }
    }

    /**
     * @Given the following users exist:
     */
    public function theFollowingUsersExist(TableNode $table)
    {
        foreach ($table->getHash() as $row) {
            $username = $row['username'];
            $email = !empty($row['email']) ? $row['email'] : uniqid($username).'@'.uniqid('example').'.com';
            $password = !empty($row['password']) ? $row['password'] : uniqid();
            $roles = !empty($row['roles']) ? preg_split('/\s*,\s*/', $row['roles'], -1, PREG_SPLIT_NO_EMPTY) : [];
            $groups = !empty($row['groups']) ? preg_split('/\s*,\s*/', $row['groups'], -1, PREG_SPLIT_NO_EMPTY) : null;

            $this->createUser($username, $email, $password, $roles, $groups);
        }
        $this->doctrine->getManager()->clear();
    }

    /**
     * @Given the following groups exist:
     */
    public function theFollowingGroupsExist(TableNode $table)
    {
        foreach ($table->getHash() as $row) {
            $title = $row['title'];

            $this->createGroup(['title' => $title]);
        }
        $this->doctrine->getManager()->clear();
    }

    /**
     * @Given /^the following (?P<entityClass>.+) entities(?: identified by (?P<idColumn>.+))? exist:$/
     *
     * @param mixed $type
     */
    public function theFollowingEntitiesExist($entityClass, $idColumn = 'id', TableNode $table)
    {
        $entityClass = trim($entityClass, '\'"');
        $idColumn = trim($idColumn, '\'"');
        if (!class_exists($entityClass)) {
            throw new \RuntimeException('Class '.$entityClass.' does not exist.');
        }

        $repository = $this->manager->getRepository($entityClass);
        $accessor = $this->container->get('property_accessor');
        foreach ($table->getHash() as $row) {
            if ($row[$idColumn] && $repository->find($row[$idColumn]) !== null) {
                continue;
            }
            $entity = new $entityClass();
            foreach ($row as $path => $value) {
                if ($path === $idColumn) {
                    $property = new \ReflectionProperty(get_class($entity), $idColumn);
                    $property->setAccessible(true);
                    $property->setValue($entity, $value);
                } else {
                    $accessor->setValue($entity, $path, $value);
                }
            }
            $this->persist($entity);
        }
    }

    /**
     * @When I authenticate as :username
     *
     * @param mixed $username
     */
    public function iAuthenticateAs($username)
    {
        $this->iSignInWithUsernameAndPassword($username, null);
    }

    /**
     * @When I sign in with username :username and password :password
     *
     * @param mixed $username
     * @param mixed $password
     */
    public function iSignInWithUsernameAndPassword($username, $password)
    {
        $user = $this->getUser($username);

        if ($user) {
            $encoder_service = $this->container->get('security.encoder_factory');
            $encoder = $encoder_service->getEncoder($user);
            if (!$password || $encoder->isPasswordValid($user->getPassword(), $password, $user->getSalt())) {
                $this->authenticate($user);
            }
        } else {
            $this->deauthenticate();
        }
    }

    /**
     * Locates url, based on provided path.
     * Override to provide custom routing mechanism.
     *
     * @param string $path
     *
     * @return string
     */
    public function locatePath($path)
    {
        $startUrl = rtrim($this->getMinkParameter('base_url'), '/').'/';

        return 0 !== strpos($path, 'http') ? $startUrl.ltrim($path, '/') : $path;
    }

    /**
     * @BeforeScenario @createSchema
     */
    public function createDatabase()
    {
        $this->schemaTool->createSchema($this->classes);
    }

    /**
     * @AfterScenario @dropSchema
     */
    public function dropDatabase()
    {
        $this->schemaTool->dropSchema($this->classes);
    }

    /**
     * @Then the JSON node :node should contain key :key
     *
     * @param mixed $node
     * @param mixed $key
     */
    public function theJsonNodeShouldContainKey($node, $key)
    {
        $json = $this->getJson();
        $actual = $this->inspector->evaluate($json, $node);
        $this->assertTrue(
            array_key_exists($key, $actual),
            sprintf('The node "%s" should contain key "%s"', $node, $key)
        );
    }

    /**
     * @Then the JSON node :node should not contain key :key
     *
     * @param mixed $node
     * @param mixed $key
     */
    public function theJsonNodeShouldNotContainKey($node, $key)
    {
        $this->not(function () use ($node, $key) {
            return $this->theJsonNodeShouldContainKey($node, $key);
        }, sprintf('The node "%s" should not contain key "%s"', $node, $key));
    }

    /**
     * @Then the JSON node :node should contain value :value
     *
     * @param mixed $node
     * @param mixed $value
     */
    public function theJsonNodeShouldContainValue($node, $value)
    {
        $json = $this->getJson();
        $actual = $this->inspector->evaluate($json, $node);
        $this->assertTrue(in_array($value, $actual, true), sprintf('The node "%s" should contain value "%s"', $node, $value));
    }

    /**
     * @Then the JSON node :node should not contain value :value
     *
     * @param mixed $node
     * @param mixed $value
     */
    public function theJsonNodeShouldNotContainValue($node, $value)
    {
        $this->not(function () use ($node, $value) {
            return $this->theJsonNodeShouldContainKey($node, $value);
        }, sprintf('The node "%s" should not contain value "%s"', $node, $value));
    }

    /**
     * Checks that a list of elements contains a specific number of nodes matching a criterion.
     *
     * @Then the JSON node :node should contain :count element(s) with :propertyPath equal to :value
     *
     * @param mixed $node
     * @param mixed $count
     * @param mixed $propertyPath
     * @param mixed $value
     */
    public function theJsonNodeShouldContainElementWithEqualTo($node, $count, $propertyPath, $value)
    {
        $json = $this->getJson();
        $items = $this->inspector->evaluate($json, $node);
        $this->assertTrue(is_array($items), sprintf('The node "%s" should be an array', $node));
        $matches = array_filter($items, function ($item) use ($propertyPath, $value) {
            $accessor = $this->container->get('property_accessor');

            return $accessor->isReadable($item, $propertyPath) && $accessor->getValue($item, $propertyPath) === $value;
        });
        $this->assertSame($count, count($matches));
    }

    /**
     * @Then the DQL query :dql should return :count element(s)
     *
     * @param mixed $dql
     * @param mixed $count
     */
    public function theDqlQueryShouldReturnElements($dql, $count)
    {
        $query = $this->manager->createQuery($dql);
        $items = $query->getResult();

        $this->assertSame($count, count($items));
    }

    /**
     * @Then the SQL query :sql should return :count element(s)
     *
     * @param mixed $sql
     * @param mixed $count
     */
    public function theSqlQueryShouldReturnElements($sql, $count)
    {
        $stmt = $this->manager->getConnection()->prepare($sql);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $this->assertSame($count, count($items));
    }

    /**
     * @Then print result of :sql
     *
     * @param mixed $sql
     */
    public function printResultOfSql($sql)
    {
        $stmt = $this->manager->getConnection()->prepare($sql);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $rows = [];
        foreach ($items as $index => $item) {
            if ($index === 0) {
                $rows[$index + 1] = array_keys($item);
            }
            // TableNode cannot handle null values.
            $rows[$index + 2] = array_map(function ($value) {
                return $value === null ? '👻' : $value;
            }, array_values($item));
        }

        if ($rows) {
            $table = new TableNode($rows);
            echo $table->getTableAsString();
        } else {
            echo '(empty)';
        }
    }

    protected function getJson()
    {
        return new Json($this->httpCallResultPool->getResult()->getValue());
    }

    private function createUser($username, $email, $password, array $roles, array $groups = null)
    {
        $userManager = $this->container->get('fos_user.user_manager');

        $user = $userManager->findUserBy(['username' => $username]);
        if (!$user) {
            $user = $userManager->createUser();
        }
        $user
            ->setEnabled(true)
            ->setUsername($username)
            ->setPlainPassword($password)
            ->setEmail($email)
            ->setRoles($roles);

        // Only set groups on new users.
        if ($groups && $user->getId() === null) {
            $groupManager = $this->container->get('os2display.group_manager');

            foreach ($groups as $spec) {
                list($groupId, $role) = preg_split('/\s*:\s*/', $spec, 2, PREG_SPLIT_NO_EMPTY);
                $group = $groupManager->findGroupBy(['id' => $groupId]);
                $userGroup = new UserGroup();
                $userGroup->setUser($user);
                $userGroup->setGroup($group);
                $userGroup->setRole($role);
                $this->doctrine->getManager()->persist($userGroup);
            }
        }

        $userManager->updateUser($user);
    }

    private function createGroup(array $data)
    {
        $manager = $this->container->get('os2display.group_manager');

        $group = $manager->findGroupBy(['title' => $data['title']]);
        if (!$group) {
            $group = $manager->createGroup($data);
        }

        $manager->updateGroup($group, $data);
    }

    /**
     * Get a user by username.
     *
     * @param $username
     *
     * @return null|User
     */
    private function getUser($username)
    {
        $repository = $this->manager->getRepository(User::class);

        return $repository->findOneBy(['username' => $username]);
    }

    /**
     * Add authentication header to request.
     */
    private function authenticate(User $user)
    {
        $firewall = 'main';

        $token = new UsernamePasswordToken($user, $user->getPassword(), $firewall, $user->getRoles());
        $this->container->get('security.token_storage')->setToken($token);
        $session = $this->container->get('session');
        $session->set('_security_user', serialize($token));
        $session->save();

        $this->getSession()->setCookie($session->getName(), $session->getId());
    }

    private function deauthenticate()
    {
        $this->container->get('security.token_storage')->setToken(null);
    }

    protected function persist($entity)
    {
        $metadata = null;
        $idGenerator = null;
        $idGeneratorType = null;
        if ($entity->getId() !== null) {
            // Remove id generator and set id manually.
            $metadata = $this->manager->getClassMetadata(get_class($entity));
            $idGenerator = $metadata->idGenerator;
            $idGeneratorType = $metadata->generatorType;
            $metadata->setIdGeneratorType($metadata::GENERATOR_TYPE_NONE);
        }

        $this->manager->persist($entity);
        // We need to flush to force the id to be set.
        $this->manager->flush();

        // Restore id generator.
        if ($metadata !== null) {
            $metadata->setIdGenerator($idGenerator);
            $metadata->setIdGeneratorType($idGeneratorType);
        }
    }
}
