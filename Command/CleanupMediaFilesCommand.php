<?php

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

class CleanupMediaFilesCommand extends ContainerAwareCommand
{
    protected function configure() {
        $this
            ->setName('os2display:core:cleanup:media')
            ->addOption(
                'force',
                NULL,
                InputOption::VALUE_NONE,
                'Delete the found files.'
            )
            ->addOption(
                'print-files',
                NULL,
                InputOption::VALUE_NONE,
                'Print the filenames of the files to be deleted.'
            )
            ->setDescription('Delete media without references in the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $force = $input->getOption('force');
        $printFiles = $input->getOption('print-files');

        $io->writeln('---------------------------');
        $io->writeln('-- Selected options -------');
        $io->writeln('---------------------------');
        $io->writeln(sprintf('force: %s', ($force ? 'true' : 'false')));
        $io->writeln(sprintf('print-files: %s', ($printFiles ? 'true' : 'false')));

        if (!$force) {
            $io->warning('No files will be deleted. To delete files run with --force option.');
        }

        $doctrine = $this->getContainer()->get('doctrine');
        $em = $doctrine->getManager();

        $media = $em->getRepository(Media::class)->findAll();

        /* @var \Os2Display\MediaBundle\Entity\Media $mediaEntity */
        $urls = [];
        $thumbs = [];

        foreach ($media as $mediaEntity) {
            $providerName = $mediaEntity->getProviderName();

            // Video
            if ($providerName === 'sonata.media.provider.zencoder') {
                /** @var \Os2Display\CoreBundle\Provider\ZencoderProvider $provider */
                // Add original file.
                $provider = $this->getContainer()->get($mediaEntity->getProviderName());
                $urls[] = $provider->getReferenceFile($mediaEntity)->getName();

                $metadata = $mediaEntity->getProviderMetadata();

                // Add processed files.
                foreach ($metadata as $data) {
                    if (isset($data['reference'])) {
                        $url = explode('/uploads/media/', $data['reference']);
                        if (isset($url[1])) {
                            $urls[] = $url[1];
                        }
                        else {
                            $io->error(sprintf('File not in "/uploads/media/": %s', json_encode($url)));
                            return;
                        }
                    }
                    if (isset($data['thumbnails'])) {
                        foreach ($data['thumbnails'] as $thumbnail) {
                            $url = explode('/uploads/media/', $thumbnail['reference']);
                            if (isset($url[1])) {
                                $thumbs[] = $url[1];
                            }
                            else {
                                $io->error(sprintf('File not in "/uploads/media/": %s', json_encode($url)));
                                return;
                            }
                        }
                    }
                }
            }
            // Image
            else if ($providerName === 'sonata.media.provider.image') {
                /** @var ImageProvider $provider */
                $provider = $this->getContainer()->get($mediaEntity->getProviderName());
                $urls[] = $provider->generatePrivateUrl($mediaEntity, 'reference');
                $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'admin');
                $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_portrait');
                $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_portrait_small');
                $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_landscape');
                $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_landscape_small');
            }
            else {
                $io->error(sprintf('Unsupported provider: %s', $providerName));
                return;
            }
        }

        $finder = new Finder();
        $files = $finder->files()->in('web/uploads/media/*/*/*');

        $uploadFiles = [];

        foreach ($files as $file) {
            $url =  explode('/web/uploads/media/', $file->getRealPath());
            if (isset($url[1])) {
                $uploadFiles[] = $url[1];
            }
            else {
                $io->error(sprintf('File not in "/web/uploads/media/": %s', json_encode($url)));
                return;
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
        $nFilesDeleted = 0;
        foreach ($filesWithoutEntity as $file) {
            if ($force) {
                $fileSystem = new Filesystem();
                $fileSystem->remove('web/uploads/media/'.$file);
                $nFilesDeleted++;
            }

            if ($printFiles) {
                $io->writeln(sprintf('%s %s', $file, $force ? 'removed' : ''));
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
        $io->writeln(sprintf('Files deleted: %d', $nFilesDeleted));
    }
}
