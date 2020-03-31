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
                'dry-run',
                NULL,
                InputOption::VALUE_OPTIONAL,
                'Execute the cleanup without applying results to database.',
                true
            )
            ->setDescription('Delete media without references in the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = $input->getOption('dry-run') === 'false' ? false : true;

        $io->writeln(sprintf('Dry-run: %s', ($dryRun ? 'true' : 'false')));

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

        $io->writeln('---------------------------');
        $io->writeln('-- Summary ----------------');
        $io->writeln('---------------------------');
        $io->writeln(sprintf('Media entities: %d', count($media)));
        $io->writeln(sprintf('Media files: %d', count($urls) + count($thumbs)));
        $io->writeln(sprintf('Files in uploads: %d', count($uploadFiles)));
        $io->writeln(sprintf('Files without entity: %d', count($filesWithoutEntity)));

        $io->writeln('---------------------------');
        $io->writeln('-- Files not in entities --');
        $io->writeln('---------------------------');
        foreach ($filesWithoutEntity as $file) {
            if (!$dryRun) {
                $fileSystem = new Filesystem();
                $fileSystem->remove('web/uploads/media/'.$file);
            }

            $io->writeln(sprintf('%s %s', $file, !$dryRun ? 'removed' : ''));
        }
    }
}
