<?php

namespace Akeneo\Nelson;

use Akeneo\Archive\PackagesExtractor;
use Akeneo\Crowdin\PackagesDownloader;
use Akeneo\Crowdin\TranslatedProgressSelector;
use Akeneo\Event\Events;
use Akeneo\Git\ProjectCloner;
use Akeneo\Git\PullRequestCreator;
use Akeneo\System\Executor;
use Github\Exception\ValidationFailedException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * TODO
 */
class PullTranslationsExecutor
{
    /**
     * @param ProjectCloner              $cloner
     * @param PullRequestCreator         $pullRequestCreator
     * @param PackagesDownloader         $downloader
     * @param TranslatedProgressSelector $status
     * @param PackagesExtractor          $extractor
     * @param TranslationFilesCleaner    $translationsCleaner
     * @param Executor                   $systemExecutor
     * @param EventDispatcherInterface   $eventDispatcher
     */
    public function __construct(
        ProjectCloner $cloner,
        PullRequestCreator $pullRequestCreator,
        PackagesDownloader $downloader,
        TranslatedProgressSelector $status,
        PackagesExtractor $extractor,
        TranslationFilesCleaner $translationsCleaner,
        Executor $systemExecutor,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->cloner              = $cloner;
        $this->pullRequestCreator  = $pullRequestCreator;
        $this->downloader          = $downloader;
        $this->status              = $status;
        $this->extractor           = $extractor;
        $this->translationsCleaner = $translationsCleaner;
        $this->systemExecutor      = $systemExecutor;
        $this->eventDispatcher     = $eventDispatcher;
    }

    /**
     * TODO Move the $options options into DI
     *
     * @param $branches
     * @param $options
     *
     * @throws \Exception
     */
    public function execute($branches, $options)
    {
        $updateDir     = $options['base_dir'] . '/update';
        $cleanerDir    = $options['base_dir'] . '/clean';
        $packages      = array_keys($this->status->packages());

        if (count($packages) > 0) {
            foreach ($branches as $baseBranch) {
                $this->eventDispatcher->dispatch(Events::PRE_NELSON_PULL, new GenericEvent(null, [
                    'branch' => $baseBranch
                ]));

                $projectDir = $this->cloner->cloneProject($updateDir, $baseBranch);
                $this->downloader->download($packages, $options['base_dir'], $baseBranch);
                $this->extractor->extract($packages, $options['base_dir'], $cleanerDir);
                $this->translationsCleaner->cleanFiles($options['locale_map'], $cleanerDir);
                $this->translationsCleaner->moveFiles($cleanerDir, $projectDir);

                try {
                    $this->pullRequestCreator->create($baseBranch, $options['base_dir'], $projectDir);
                } catch (ValidationFailedException $exception) {
                    // TODO Move this
                    $message = sprintf(
                        'No PR created for version "%s", message "%s"',
                        $baseBranch,
                        $exception->getMessage()
                    );
                }

                $this->eventDispatcher->dispatch(Events::POST_NELSON_PULL);
            }

            $this->systemExecutor->execute(sprintf('rm -rf %s', $options['base_dir'] . '/update'));
        }
    }
}
