<?php
/**
 * @file
 * Contains the feed service.
 */

namespace Os2Display\CoreBundle\Services;

use Debril\RssAtomBundle\Exception\RssAtomException;
use Os2Display\CoreBundle\Events\CronEvent;

/**
 * Class FeedService
 * @package Os2Display\CoreBundle\Services
 */
class FeedService {
  private $container;
  private $entityManager;
  private $slideRepo;

  /**
   * Constructor.
   *
   * @param $container
   */
  public function __construct($container) {
    $this->container = $container;
    $this->entityManager = $this->container->get('doctrine')->getManager();
    $this->slideRepo = $container->get('doctrine')->getRepository('Os2DisplayCoreBundle:Slide');
  }

  /**
   * ik.onCron event listener.
   *
   * Updates feed slides.
   *
   * @param CronEvent $event
   */
  public function onCron(CronEvent $event) {
    $this->updateFeedSlides();
  }

  /**
   * Update the externalData for feed slides.
   */
  public function updateFeedSlides() {
    $cache = array();

    $slides = $this->slideRepo->findBySlideType('rss');

    foreach ($slides as $slide) {
      $options = $slide->getOptions();

      if (empty($options['source'])) {
        continue;
      }

      $source = $options['source'];

      $md5Source = md5($source);

      // Check for previouslyDownloaded feed.
      if (array_key_exists($md5Source, $cache)) {
        // Save in externalData field
        $slide->setExternalData($cache[$md5Source]);

        $this->entityManager->flush();
      }
      else {
        // Fetch the FeedReader
        $reader = $this->container->get('feedio');

        try {
          // Fetch content
          $feed = $reader->read($source)->getFeed();

          // Setup return array.
          $res = array(
            array(
              'feed' => array(),
              'title' => $feed->getTitle(),
            ),
          );

          // Get all items.
          /** @var \FeedIo\Feed\Item $item */
          foreach ($feed as $item) {
            $res[0]['feed'][] = array(
              'title' => $item->getTitle(),
              'date' => $item->getLastModified()->format('U'),
              'description' => $item->getDescription(),
            );
          }

          // Cache the result for next iteration.
          $cache[$md5Source] = $res;

          // Save in externalData field
          $slide->setExternalData($res);

          $this->entityManager->flush();
        }
        catch (\Exception $e) {
          $logger = $this->container->get('logger');
          $logger->warning('FeedService: Unable to download feed from ' . $source);
          $logger->warning($e);

          // Ignore exceptions, and just leave the content that has already been stored.
        }
      }
    }
  }
}
