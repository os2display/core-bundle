<?php

namespace Os2Display\CoreBundle\Command;

use Os2Display\MediaBundle\Entity\Media;
use Sonata\MediaBundle\Provider\ImageProvider;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

class CleanupMediaFilesCommand extends ContainerAwareCommand
{
    protected function configure() {
        $this
            ->setName('os2display:core:cleanup:media')
            ->addOption(
                'dry-run',
                NULL,
                InputOption::VALUE_NONE,
                'Execute the cleanup without applying results to database.'
            )
            ->setDescription('Delete media without references in the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $doctrine = $this->getContainer()->get('doctrine');
        $em = $doctrine->getManager();

        $media = $em->getRepository(Media::class)->findAll();

        $io->writeln(sprintf('Media entities: %d', count($media)));

        /* @var \Os2Display\MediaBundle\Entity\Media $mediaEntity */
        $urls = [];
        $thumbs = [];
        foreach ($media as $mediaEntity) {
            $providerName = $mediaEntity->getProviderName();

            // Video
            if ($providerName === 'sonata.media.provider.zencoder') {
                $metadata = $media->getProviderMetadata();
                $mediaUrls = array();

                foreach ($metadata as $data) {
                    $mediaUrls[$data['label']] = $data['reference'];
                }

                $thumbs[] = $metadata[0]['thumbnails'][1]['reference'];
                $urls[] = $mediaUrls;
            }
            // Image
            else {
                if ($providerName === 'sonata.media.provider.image') {

                    /** @var ImageProvider $provider */
                    $provider = $this->getContainer()->get($mediaEntity->getProviderName());
                    $urls[] = $provider->generatePrivateUrl($mediaEntity, 'reference');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'admin');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_portrait');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_portrait_small');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_landscape');
                    $thumbs[] = $provider->generatePrivateUrl($mediaEntity, 'default_landscape_small');
                }
            }
        }

        $io->writeln(sprintf('Media files: %d', count($urls) + count($thumbs)));

        // $io->writeln('Files: '. json_encode($urls));
        // $io->writeln('Thumbs: '. json_encode($thumbs));

        $finder = new Finder();
        $files = $finder->files()->in('web/uploads/media/*/*/*');

        $uploadFiles = [];

        foreach ($files as $file) {
            $uploadFiles[] = explode('/app/web/uploads/media/', $file->getRealPath())[1];
        }

        $io->writeln(sprintf('Files in uploads: %d', count($uploadFiles)));
        // $io->writeln(sprintf('Files in uploads: %s', json_encode($uploadFiles)));

        $io->writeln('---------------------------');
        $io->writeln('-- Files not in entities --');
        $io->writeln('---------------------------');

        $filesWithoutEntity = [];

        foreach ($uploadFiles as $uploadFile) {
            if (!in_array($uploadFile, $urls) && !in_array($uploadFile, $thumbs)) {
                $io->writeln(sprintf('%s', $uploadFile));
                $filesWithoutEntity[] = $uploadFile;
            }
        }

        $io->writeln(sprintf('Files without entity: %d', count($filesWithoutEntity)));
    }
}
