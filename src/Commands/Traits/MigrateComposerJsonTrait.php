<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Throwable;

/**
 * Trait MigrateComposerJsonTrait.
 */
trait MigrateComposerJsonTrait
{
    use ComposerAwareTrait;
    use GitAwareTrait;

    /**
     * @var array
     */
    private array $sourceComposerJson;

    /**
     * Migrates composer.json components.
     *
     * @param array $sourceComposerJson
     *   Content of the source composer.json file.
     * @param string $projectPath
     *   Path to Composer project.
     * @param array $contribProjects
     *   Drupal contrib dependencies.
     * @param array $libraryProjects
     *   Drupal library dependencies.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function migrateComposerJson(
        array  $sourceComposerJson,
        string $projectPath,
        array  $contribProjects = [],
        array  $libraryProjects = []
    ): void {
        $this->log()->notice('Migrating Composer project components...');

        $this->sourceComposerJson = $sourceComposerJson;
        $this->setComposer($projectPath);

        $this->copyMinimumStability();
        $this->addDrupalComposerPackages($contribProjects);
        $this->addComposerPackages($libraryProjects);

        $missingPackages = $this->getMissingComposerPackages($this->getComposer()->getComposerJsonData());
        $this->addComposerPackages($missingPackages);
        $this->log()->notice(
            <<<EOD
Composer require and require-dev sections have been migrated. Look at the logs for any errors in the process.
EOD
        );
        $this->log()->notice(
            <<<EOD
Please note that other composer.json sections: repositories, config, extra, etc. should be manually migrated if needed.
EOD
        );

        $this->copyComposerPackagesConfiguration();
    }

    /**
     * Adds Drupal contrib project dependencies to composer.json.
     *
     * @param array $contribPackages
     */
    private function addDrupalComposerPackages(array $contribPackages): void
    {
        try {
            foreach ($this->getDrupalComposerDependencies() as $dependency) {
                $arguments = [$dependency['package'], $dependency['version'], '--no-update'];
                if ($dependency['is_dev']) {
                    $arguments[] = '--dev';
                }

                $this->getComposer()->require(...$arguments);
                if ($this->getGit()->isAnythingToCommit()) {
                    $this->getGit()->commit(
                        sprintf('Add %s (%s) project to Composer', $dependency['package'], $dependency['version'])
                    );
                    $this->log()->notice(sprintf('%s (%s) is added', $dependency['package'], $dependency['version']));
                }
            }

            $this->getComposer()->install('--no-dev');
            if ($this->getGit()->isAnythingToCommit()) {
                $this->getGit()->commit('Install composer packages');
            }
        } catch (Throwable $t) {
            $this->log()->warning(
                sprintf(
                    'Failed adding and/or installing Drupal 8 dependencies: %s',
                    $t->getMessage()
                )
            );
        }

        foreach ($contribPackages as $project) {
            $packageName = sprintf('drupal/%s', $project['name']);
            $packageVersion = sprintf('^%s', $project['version']);
            try {
                $this->getComposer()->require($packageName, $packageVersion);
                $this->getGit()->commit(sprintf('Add %s (%s) project to Composer', $packageName, $packageVersion));
                $this->log()->notice(sprintf('%s (%s) is added', $packageName, $packageVersion));
            } catch (Throwable $t) {
                $this->log()->warning(
                    sprintf(
                        'Failed adding %s (%s) composer package: %s',
                        $packageName,
                        $packageVersion,
                        $t->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Returns the list of Drupal composer dependencies.
     *
     * @return array[]
     *   Each dependency is an array that consists of the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     */
    private function getDrupalComposerDependencies(): array
    {
        $drupalConstraint = $this->sourceComposerJson['require']['drupal/core-recommended']
            ?? $this->sourceComposerJson['require']['drupal/core']
            ?? '^8.9';

        $drupalIntegrationsConstraint = preg_match('/^[^0-9]*9/', $drupalConstraint) ? '^9' : '^8';

        return [
            [
                'package' => 'drupal/core-recommended',
                'version' => $drupalConstraint,
                'is_dev' => false,
            ],
            [
                'package' => 'pantheon-systems/drupal-integrations',
                'version' => $drupalIntegrationsConstraint,
                'is_dev' => false,
            ],
            [
                'package' => 'drupal/core-dev',
                'version' => $drupalConstraint,
                'is_dev' => true,
            ],
        ];
    }

    /**
     * Returns the list of missing packages after comparing original and current composer json files.
     *
     * @return array[]
     *   Each dependency is an array that consists of the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     */
    private function getMissingComposerPackages(array $currentComposerJson): array
    {
        $missingPackages = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach ($this->sourceComposerJson[$section] ?? [] as $package => $version) {
                if (isset($currentComposerJson[$section][$package])) {
                    continue;
                }
                $missingPackages[] = ['package' => $package, 'version' => $version, 'is_dev' => 'require' !== $section];
            }
        }
        return $missingPackages;
    }


    /**
     * Adds dependencies to composer.json.
     *
     * @param array $packages The list of packages to add.
     *   It could be just the name or an array with the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     */
    private function addComposerPackages(array $packages): void
    {
        foreach ($packages as $project) {
            if (is_string($project)) {
                $project = [
                    'package' => $project,
                    'version' => null,
                    'is_dev' => false,
                ];
            }
            $package = $project['package'];
            $arguments = [$project['package'], $project['version']];
            $options = $project['is_dev'] ? ['--dev'] : [];
            $options[] = '-n';
            $options[] = '-W';
            try {
                $this->getComposer()->require(...$arguments, ...$options);
                $this->getGit()->commit(sprintf('Add %s project to Composer', $package));
                $this->log()->notice(sprintf('%s is added', $package));
            } catch (Throwable $t) {
                $this->log()->warning(
                    sprintf('Failed adding %s composer package: %s', $package, $t->getMessage())
                );
            }
        }
    }

    /**
     * Copy composer well-known packages configuration.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function copyComposerPackagesConfiguration(): void
    {
        $this->copyComposerPatchesConfiguration();
        $this->copyComposerInstallersExtenderConfiguration();
        $this->copyExtraComposerInstallersConfiguration();
        if ($this->getGit()->isAnythingToCommit()) {
            $this->getGit()->commit('Copy extra composer configuration.');
        } else {
            $this->log()->notice('No extra composer configuration found.');
        }
    }

    /**
     * Copy cweagans/composer-patches configuration if exists.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyComposerPatchesConfiguration(): void
    {
        $packageName = 'cweagans/composer-patches';
        $extraKeys = [
            'patches',
            'patches-file',
            'enable-patching',
            'patches-ignore',
            'composer-exit-on-patch-failure',
        ];
        if (!isset($this->sourceComposerJson['require'][$packageName])
            && !isset($this->sourceComposerJson['require-dev'][$packageName])
        ) {
            return;
        }
        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        foreach ($extraKeys as $key) {
            if (isset($this->sourceComposerJson['extra'][$key])) {
                $currentComposerJson['extra'][$key] = $this->sourceComposerJson['extra'][$key];
                if ($key === 'patches-file') {
                    $this->log()->warning(
                        <<<EOD
cweagans/composer-patches patches-file option was copied, but you should manually copy the patches file.
EOD
                    );
                }
            }
        }
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }

    /**
     * Copy oomphinc/composer-installers-extender configuration if exists.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyComposerInstallersExtenderConfiguration(): void
    {
        $packageName = 'oomphinc/composer-installers-extender';
        if (!isset($this->sourceComposerJson['require'][$packageName])
            && !isset($this->sourceComposerJson['require-dev'][$packageName])
        ) {
            return;
        }
        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        if (isset($this->sourceComposerJson['extra']['installer-types'])) {
            $installerTypes = $this->sourceComposerJson['extra']['installer-types'];
            $currentComposerJson['extra']['installer-types'] =
                $this->sourceComposerJson['extra']['installer-types'];
            foreach ($this->sourceComposerJson['extra']['installer-paths'] ?? [] as $path => $types) {
                if (array_intersect($installerTypes, $types)) {
                    $currentComposerJson['extra']['installer-paths'][$path] = $types;
                }
            }
        }
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }

    /**
     * Copy extra composer/installer configuration if exists.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyExtraComposerInstallersConfiguration(): void
    {
        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        $currentTypes = [];

        $installerPaths = [];
        if (isset($currentComposerJson['extra']['installer-paths'])) {
            $installerPaths = &$currentComposerJson['extra']['installer-paths'];
        }
        foreach ($installerPaths as $path => $types) {
            $currentTypes += $types;
        }

        foreach ($this->sourceComposerJson['extra']['installer-paths'] ?? [] as $path => $types) {
            if (!isset($installerPaths[$path])) {
                foreach ($types as $type) {
                    if (in_array($type, $currentTypes)) {
                        continue;
                    }
                    $installerPaths[$path][] = $type;
                }
            } else {
                if ($installerPaths[$path] !== $types) {
                    $installerPaths[$path] = array_values(array_unique(array_merge($installerPaths[$path], $types)));
                }
            }
        }
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }

    /**
     * Copy minimum stability setting
     */
    private function copyMinimumStability(): void
    {
        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        if (isset($this->sourceComposerJson['minimum-stability'])) {
            $currentComposerJson['minimum-stability'] = $this->sourceComposerJson['minimum-stability'];
        }
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }
}
