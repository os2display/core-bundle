<?php
/**
 * @file
 * Contains command to cleanup media folder of files that are not connected to a Media entities.
 */

namespace Os2Display\CoreBundle\Command;

use Os2Display\MediaBundle\Entity\Media;
use Sonata\MediaBundle\Provider\ImageProvider;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Class CleanupMediaFilesCommand.
 */
class CleanupMediaFilesCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('os2display:core:cleanup:media')
            ->addOption(
                'delete-files',
                null,
                InputOption::VALUE_NONE,
                'Delete the found files.'
            )
            ->addOption(
                'move-files',
                null,
                InputOption::VALUE_OPTIONAL,
                'Move the found files to the given directory.',
                false
            )
            ->addOption(
                'print-files',
                null,
                InputOption::VALUE_NONE,
                'Print the filenames of the files to be deleted.'
            )
            ->setDescription('Delete media without references in the database.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $io = new SymfonyStyle($input, $output);

        $deleteFiles = $input->getOption('delete-files');
        $moveFiles = $input->getOption('move-files');
        $printFiles = $input->getOption('print-files');

        $io->writeln('---------------------------');
        $io->writeln('-- Selected options -------');
        $io->writeln('---------------------------');
        $io->writeln(sprintf('delete-files: %s', ($deleteFiles ? 'true' : 'false')));
        $io->writeln(sprintf('print-files: %s', ($printFiles ? 'true' : 'false')));
        $io->writeln(sprintf('move-files: %s', ($moveFiles === false ? 'false' : $moveFiles)));

        if ($deleteFiles && $moveFiles !== false) {
            $io->error('--delete-files and --move-files options cannot both be set.');
            return 1;
        }

        if (!$deleteFiles && $moveFiles === false) {
            $io->warning('No files will be deleted or moved. To delete files run with --delete-files option. To move files run with --move-files=/path/to/move/to/');
        }

        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();

        $media = $em->getRepository(Media::class)->findAll();

        $urls = [];
        $thumbs = [];

        /* @var \Os2Display\MediaBundle\Entity\Media $mediaEntity */
        foreach ($media as $mediaEntity) {
            $providerName = $mediaEntity->getProviderName();

            // Video
            if ($providerName === 'sonata.media.provider.zencoder') {
                /** @var \Os2Display\CoreBundle\Provider\ZencoderProvider $provider */
                $provider = $this->getContainer()->get($mediaEntity->getProviderName());
                // Add original file.
                $urls[] = $provider->getReferenceFile($mediaEntity)->getName();

                $metadata = $mediaEntity->getProviderMetadata();

                // Add processed files.
                foreach ($metadata as $data) {
                    if (isset($data['reference'])) {
                        $url = explode('/uploads/media/', $data['reference']);
                        if (isset($url[1])) {
                            $urls[] = $url[1];
                        } else {
                            $io->error(sprintf('Filename not in "/uploads/media/": %s', json_encode($url)));

                            return 1;
                        }
                    }
                    if (isset($data['thumbnails'])) {
                        foreach ($data['thumbnails'] as $thumbnail) {
                            $url = explode('/uploads/media/', $thumbnail['reference']);
                            if (isset($url[1])) {
                                $thumbs[] = $url[1];
                            } else {
                                $io->error(sprintf('Filename not in "/uploads/media/": %s', json_encode($url)));

                                return 1;
                            }
                        }
                    }
                }
            }
            else {
                // Image
                if ($providerName === 'sonata.media.provider.image') {
                    /** @var ImageProvider $provider */
                    $provider = $this->getContainer()->get($mediaEntity->getProviderName());
                    $urls[] = $provider->generatePrivateUrl($mediaEntity, 'reference');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'admin');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_portrait');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_portrait_small');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_landscape');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_landscape_small');
                } else {
                    $io->error(sprintf('Unsupported provider: %s', $providerName));

                    return 1;
                }
            }
        }

        $finder = new Finder();
        $files = $finder->files()->in('web/uploads/media/*/*/*');

        $uploadFiles = [];

        foreach ($files as $file) {
            $url = explode('/web/uploads/media/', $file->getRealPath());
            if (isset($url[1])) {
                $uploadFiles[] = $url[1];
            } else {
                $io->error(sprintf('File not in "/web/uploads/media/": %s', json_encode($url)));

                return 1;
            }
        }

        $filesWithoutEntity = [];

        foreach ($uploadFiles as $uploadFile) {
            if (!in_array($uploadFile, $urls) && !in_array($uploadFile, $thumbs)) {
                $filesWithoutEntity[] = $uploadFile;
            }
        }

        if ($printFiles) {
            $io->writeln('---------------------------');
            $io->writeln('-- Files not in entities --');
            $io->writeln('---------------------------');
        }

        $fileSystem = new Filesystem();
        $projectDir = $container->get('kernel')->getProjectDir();
        $nFilesDeleted = 0;
        $nFilesMoved = 0;
        $fileSizeToBeDeleted = 0;

        if ($moveFiles !== false) {
            $fileSystem->mkdir($moveFiles);
        }

        foreach ($filesWithoutEntity as $file) {
            $fileSize = filesize($projectDir.'/web/uploads/media/'.$file) ?? 0;
            $fileSizeToBeDeleted = $fileSizeToBeDeleted + $fileSize;

            if ($deleteFiles) {
                $fileSystem->remove($projectDir.'/web/uploads/media/'.$file);
                $nFilesDeleted++;
            }
            else if ($moveFiles !== false) {
                $folders = explode('/', $file);
                // Remove filename from folders.
                array_pop($folders);

                // Make sure target folder exists.
                $currentFolder = $moveFiles;
                for ($i = 0; $i < count($folders); $i++) {
                    $currentFolder = $currentFolder.'/'.$folders[$i];
                    $fileSystem->mkdir($currentFolder);
                }

                $fileSystem->rename($projectDir.'/web/uploads/media/'.$file, $moveFiles.$file);
                $nFilesMoved++;
            }

            if ($printFiles) {
                $io->writeln(sprintf('%s %s%s', $file, $deleteFiles ? 'removed' : '',  $moveFiles !== null ? 'moved' : ''));
            }
        }
        $io->writeln('');
        $io->writeln('---------------------------');
        $io->writeln('-- Summary ----------------');
        $io->writeln('---------------------------');
        $io->writeln(sprintf('Media entities: %d', count($media)));
        $io->writeln(sprintf('Media files: %d', count($urls) + count($thumbs)));
        $io->writeln(sprintf('Files in uploads: %d', count($uploadFiles)));
        $io->writeln(sprintf('Files without entity: %d', count($filesWithoutEntity)));
        $io->writeln(sprintf('Files without entity file size: %d bytes', $fileSizeToBeDeleted));
        $io->writeln(sprintf('Files deleted: %d', $nFilesDeleted));
        $io->writeln(sprintf('Files moved: %d', $nFilesMoved));

        return 0;
    }
}
