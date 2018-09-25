<?php
/**
 * @file
 * This file is a part of the Os2Display CoreBundle.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Os2Display\CoreBundle\Command;

use Os2Display\CoreBundle\Events\CronEvent;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Os2Display\MediaBundle\Entity\Media;

/**
 * Class CleanupCommand
 *
 * @package Os2Display\CoreBundle\Command
 */
class CleanupCommand extends ContainerAwareCommand {
  /**
   * Configure the command
   */
  protected function configure() {
    $this
      ->setName('os2display:core:cleanup')
      ->addOption(
        'dry-run',
        NULL,
        InputOption::VALUE_NONE,
        'Execute the cleanup without applying results to database.'
      )
      ->setDescription('Delete old and unused content.');
  }

  /**
   * Delete an array of entities.
   *
   * @param $entityList
   * @param $cleanupService
   * @param $output
   * @param bool $dryRun
   *
   * @return int Number of deleted items.
   */
  private function deleteEntities($entityList, $cleanupService, $output, $dryRun = true) {
    $entitiesDeleted = 0;

    foreach ($entityList as $entity) {
      $deleteSuccess = true;

      $name = null;
      $id = $entity->getId();

      if (get_class($entity) == Media::class) {
        $name = $entity->getName();
      }
      else {
        $name = $entity->getTitle();
      }

      if (!$dryRun) {
        $deleteSuccess = $cleanupService->deleteEntity($entity);
        $entitiesDeleted++;
      }

      if ($deleteSuccess) {
        $output->writeln('Deleted "' . $name .'" (id: ' . $id . ')');
      }
      else {
        $output->writeln('Error deleting "' . $name .'" (id: ' . $id . ')');
      }
    }

    return $entitiesDeleted;
  }

  /**
   * Executes the command
   *
   * @param InputInterface $input
   * @param OutputInterface $output
   * @return int|null|void
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);

    $dryRun = false;

    if ($input->hasOption('dry-run')) {
      $dryRun = $input->getOption('dry-run');
      $output->writeln('Dry-run: ' . ($dryRun ? 'true' : 'false'));
    }

    $deleteBeforeDate = $io->confirm('Set threshold for deletion?', false);
    $timestampThreshold = null;
    $numberOfDeletedMedia = 0;
    $numberOfDeletedSlides = 0;
    $numberOfDeletedChannels = 0;

    if ($deleteBeforeDate) {
      $oneYearAgo = strtotime("-1 year", time());
      $date = date("Y-m-d", $oneYearAgo);

      $selectedDate = $io->ask('Which date should be the threshold for deletion? Only items last modified before this date are removed.', $date);

      $timestampThreshold = date('U', strtotime($selectedDate));

      $output->writeln('Selected date: ' . $selectedDate . " (" . $timestampThreshold . ")");
    }

    $cleanupService = $this->getContainer()->get('os2display.core.cleanup_service');

    // Deleting Channels
    $channelList = $cleanupService->findChannelsToDelete($timestampThreshold);
    if (count($channelList) > 0) {
      $confirm = $io->confirm('This will delete ' . count($channelList) . ' channels. Do you wish to continue?', false);
      if (!$confirm) {
        $output->writeln('Cleanup cancelled!');
        return;
      }
      $output->writeln('Deleting channels...');
      $numberOfDeletedChannels = $this->deleteEntities($channelList, $cleanupService, $output, $dryRun);
      $output->writeln('');
    }
    else {
      $output->writeln('No channels found for deletion.');
    }

    // Deleting Slides
    $slideList = $cleanupService->findSlidesToDelete($timestampThreshold);
    if (count($slideList) > 0) {
      $confirm = $io->confirm('This will delete ' . count($slideList) . ' slides. Do you wish to continue?', false);
      if (!$confirm) {
        $output->writeln('Cleanup cancelled!');
        return;
      }
      $output->writeln('Deleting slides...');
      $numberOfDeletedSlides = $this->deleteEntities($slideList, $cleanupService, $output, $dryRun);
      $output->writeln('');
    }
    else {
      $output->writeln('No slides found for deletion.');
    }

    // Deleting Media
    $mediaList = $cleanupService->findMediaToDelete($timestampThreshold);
    if (count($mediaList) > 0) {
      $confirm = $io->confirm('This will delete ' . count($mediaList) . ' media. Do you wish to continue?', false);
      if (!$confirm) {
        $output->writeln('Cleanup cancelled!');
        return;
      }
      $output->writeln('Deleting media...');
      $numberOfDeletedMedia = $this->deleteEntities($mediaList, $cleanupService, $output, $dryRun);
      $output->writeln('');
    }
    else {
      $output->writeln('No media found for deletion.');
    }

    $output->writeln('');
    $output->writeln('### Summery ###');
    if ($dryRun) {
      $output->writeln('Dry-run enabled. No entities deleted.');
    }
    $output->writeln('Channels deleted: ' . $numberOfDeletedChannels);
    $output->writeln('Slides deleted: ' . $numberOfDeletedSlides);
    $output->writeln('Media deleted: ' . $numberOfDeletedMedia);
    $output->writeln('### Summery ###');
    $output->writeln('');
    $output->writeln('Cleanup done.');
  }
}
