<?php

declare(strict_types=1);

namespace EcEuropa\Toolkit\TaskRunner\Commands;

use Composer\Semver\Semver;
use EcEuropa\Toolkit\TaskRunner\AbstractCommands;
use EcEuropa\Toolkit\Website;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

class ComponentCheckCommands extends AbstractCommands
{
    protected bool $commandFailed = false;
    protected bool $mandatoryFailed = false;
    protected bool $recommendedFailed = false;
    protected bool $insecureFailed = false;
    protected bool $outdatedFailed = false;
    protected bool $devVersionFailed = false;
    protected bool $devCompRequireFailed = false;
    protected bool $drushRequireFailed = false;
    protected bool $skipOutdated = false;
    protected bool $skipInsecure = false;

    /**
     * Check composer.json for components that are not whitelisted/blacklisted.
     *
     * @command toolkit:component-check
     *
     * @option endpoint Deprecated
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function componentCheck(array $options = [
        'endpoint' => InputOption::VALUE_OPTIONAL,
        'test-command' => false,
    ])
    {
        if (!empty($options['endpoint'])) {
            Website::setUrl($options['endpoint']);
        }
        if (empty($basicAuth = Website::basicAuth())) {
            return 1;
        }

        $commitTokens = ToolCommands::getCommitTokens();
        if (isset($commitTokens['skipOutdated'])) {
            $this->skipOutdated = true;
        }
        if (isset($commitTokens['skipInsecure'])) {
            $this->skipInsecure = true;
        }

        $endpoint = Website::url();
        $composerLock = file_exists('composer.lock') ? json_decode(file_get_contents('composer.lock'), true) : false;

        if (!isset($composerLock['packages'])) {
            $this->io()->error('No packages found in the composer.lock file.');
            return 1;
        }

        $status = 0;
        $result = Website::get($endpoint . '/api/v1/package-reviews?version=8.x', $basicAuth);
        $data = json_decode($result, true);
        $modules = array_filter(array_combine(array_column($data, 'name'), $data));

        // To test this command execute it with the --test-command option:
        // ./vendor/bin/run toolkit:component-check --test-command --endpoint="https://webgate.ec.europa.eu/fpfis/qa"
        // Then we provide an array in the packages that fails on each type
        // of validation.
        if ($options['test-command']) {
            $composerLock['packages'] = $this->testPackages();
        }

        // Execute all checks.
        $checks = [
            'Mandatory',
            'Recommended',
            'Insecure',
            'Outdated',
        ];
        foreach ($checks as $check) {
            $this->io()->title("Checking $check components.");
            $fct = "component$check";
            $this->{$fct}($modules, $composerLock['packages']);
            $this->io()->newLine();
        }

        // Get vendor list from 'api/v1/toolkit-requirements' endpoint.
        $tkReqsEndpoint = $endpoint . '/api/v1/toolkit-requirements';
        $resultTkReqsEndpoint = Website::get($tkReqsEndpoint, $basicAuth);
        $dataTkReqsEndpoint = json_decode($resultTkReqsEndpoint, true);
        $vendorList = $dataTkReqsEndpoint['vendor_list'] ?? [];

        $this->io()->title('Checking evaluation status components.');
        // Proceed with 'blocker' option. Loop over the packages.
        foreach ($composerLock['packages'] as $package) {
            // Check if vendor belongs to the monitorised vendor list.
            if (in_array(explode('/', $package['name'])['0'], $vendorList)) {
                $this->validateComponent($package, $modules);
            }
        }
        if ($this->commandFailed === false) {
            $this->say('Evaluation module check passed.');
        }
        $this->io()->newLine();

        $this->io()->title('Checking dev components.');
        foreach ($composerLock['packages'] as $package) {
            $typeBypass = in_array($package['type'], [
                'drupal-custom-module',
                'drupal-custom-theme',
                'drupal-custom-profile',
            ]);
            if (!$typeBypass && preg_match('[^dev\-|\-dev$]', $package['version'])) {
                $this->devVersionFailed = true;
                $this->say("Package {$package['name']}:{$package['version']} cannot be used in dev version.");
            }
        }
        if (!$this->devVersionFailed) {
            $this->say('Dev components check passed.');
        }
        $this->io()->newLine();

        $this->io()->title('Checking dev components in require section.');
        $devPackages = array_filter(
            array_column($modules, 'dev_component', 'name'),
            function ($value) {
                return $value == 'true';
            }
        );
        foreach ($devPackages as $packageName => $package) {
            if (ToolCommands::getPackagePropertyFromComposer($packageName, 'version', 'packages')) {
                $this->devCompRequireFailed = true;
                $this->io()->warning("Package $packageName cannot be used on require section, must be on require-dev section.");
            }
        }
        if (!$this->devCompRequireFailed) {
            $this->say('Dev components in require section check passed');
        }
        $this->io()->newLine();

        $this->io()->title('Checking require section for Drush.');
        if (ToolCommands::getPackagePropertyFromComposer('drush/drush', 'version', 'packages-dev')) {
            $this->drushRequireFailed = true;
            $this->io()->warning("Package 'drush/drush' cannot be used in require-dev, must be on require section.");
        }

        if (!$this->drushRequireFailed) {
            if (ToolCommands::getPackagePropertyFromComposer('drush/drush', 'version', 'packages')) {
                $this->say('Drush require section check passed.');
            }
        }
        $this->io()->newLine();

        $this->printComponentResults();

        // If the validation fail, return according to the blocker.
        if (
            $this->commandFailed ||
            $this->mandatoryFailed ||
            $this->recommendedFailed ||
            $this->devVersionFailed ||
            $this->devCompRequireFailed ||
            $this->drushRequireFailed ||
            (!$this->skipInsecure && $this->insecureFailed)
        ) {
            $msg = [
                'Failed the components check, please verify the report and update the project.',
                'See the list of packages at https://webgate.ec.europa.eu/fpfis/qa/package-reviews.',
            ];
            $this->io()->error($msg);
            $status = 1;
        }

        // Give feedback if no problems found.
        if (!$status) {
            $this->io()->success('Components checked, nothing to report.');
        } else {
            $this->io()->note([
                'NOTE: It is possible to bypass the insecure and outdated check by providing a token in the commit message.',
                'The available tokens are:',
                '    - [SKIP-OUTDATED]',
                '    - [SKIP-INSECURE]',
            ]);
        }

        return $status;
    }

    /**
     * Helper function to validate the component.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function printComponentResults()
    {
        $this->io()->title('Results:');

        $skipInsecure = ($this->skipInsecure) ? ' (Skipping)' : '';
        $skipOutdated = ($this->skipOutdated) ? '' : ' (Skipping)';

        $this->io()->definitionList(
            ['Mandatory module check ' => $this->mandatoryFailed ? 'failed' : 'passed'],
            ['Recommended module check ' => ($this->recommendedFailed ? 'failed' : 'passed') . ' (report only)'],
            ['Insecure module check ' => $this->insecureFailed ? 'failed' : 'passed' . $skipInsecure],
            ['Outdated module check ' => $this->outdatedFailed ? 'failed' : 'passed' . $skipOutdated],
            ['Dev module check ' => $this->devVersionFailed ? 'failed' : 'passed'],
            ['Evaluation module check ' => $this->commandFailed ? 'failed' : 'passed'],
            ['Dev module in require-dev check ' => $this->devCompRequireFailed ? 'failed' : 'passed'],
            ['Drush require section check ' => $this->drushRequireFailed ? 'failed' : 'passed'],
        );
    }

    /**
     * Helper function to validate the component.
     *
     * @param array $package The package to validate.
     * @param array $modules The modules list.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function validateComponent($package, $modules)
    {
        // Only validate module components for this time.
        if (!isset($package['type']) || $package['type'] !== 'drupal-module') {
            return;
        }
        $config = $this->getConfig();
        $packageName = $package['name'];
        $hasBeenQaEd = isset($modules[$packageName]);
        $wasRejected = isset($modules[$packageName]['restricted_use']) && $modules[$packageName]['restricted_use'] !== '0';
        $wasNotRejected = isset($modules[$packageName]['restricted_use']) && $modules[$packageName]['restricted_use'] === '0';
        $packageVersion = isset($package['extra']['drupal']['version']) ? explode('+', str_replace('8.x-', '', $package['extra']['drupal']['version']))[0] : $package['version'];
        $allowedProjectTypes = !empty($modules[$packageName]['allowed_project_types']) ? $modules[$packageName]['allowed_project_types'] : '';
        $allowedProfiles = !empty($modules[$packageName]['allowed_profiles']) ? $modules[$packageName]['allowed_profiles'] : '';

        // Exclude invalid.
        $packageVersion = in_array($packageVersion, $config->get('toolkit.invalid-versions')) ? $package['version'] : $packageVersion;

        // If module was not reviewed yet.
        if (!$hasBeenQaEd) {
            $this->say("Package $packageName:$packageVersion has not been reviewed by QA.");
            $this->commandFailed = true;
        }

        // If module was rejected.
        if ($hasBeenQaEd && $wasRejected) {
            $projectId = $config->get('toolkit.project_id');
            // Check if the module is allowed for this project id.
            $allowedInProject = in_array($projectId, array_map('trim', explode(',', $modules[$packageName]['restricted_use'])));

            // Check if the module is allowed for this type of project.
            if (!$allowedInProject && !empty($allowedProjectTypes)) {
                $allowedProjectTypes = array_map('trim', explode(',', $allowedProjectTypes));
                // Load the project from the website.
                $project = Website::projectInformation($projectId);
                if (in_array($project['type'], $allowedProjectTypes)) {
                    $allowedInProject = true;
                }
            }

            // Check if the module is allowed for this profile.
            if (!$allowedInProject && !empty($allowedProfiles)) {
                $allowedProfiles = array_map('trim', explode(',', $allowedProfiles));
                // Load the project from the website.
                $project = Website::projectInformation($projectId);
                if (in_array($project['profile'], $allowedProfiles)) {
                    $allowedInProject = true;
                }
            }

            // If module was not allowed in project.
            if (!$allowedInProject) {
                $this->say("The use of $packageName:$packageVersion is {$modules[$packageName]['status']}. Contact QA Team.");
                $this->commandFailed = true;
            }
        }

        if ($wasNotRejected) {
            # Once all projects are using Toolkit >=4.1.0, the 'version' key
            # may be removed from the endpoint: /api/v1/package-reviews.
            $constraints = [ 'whitelist' => false, 'blacklist' => true ];
            foreach ($constraints as $constraint => $result) {
                $constraintValue = !empty($modules[$packageName][$constraint]) ? $modules[$packageName][$constraint] : null;
                if (!is_null($constraintValue) && Semver::satisfies($packageVersion, $constraintValue) === $result) {
                    echo "Package $packageName:$packageVersion does not meet the $constraint version constraint: $constraintValue." . PHP_EOL;
                    $this->commandFailed = true;
                }
            }
        }
    }

    /**
     * Helper function to check component's review information.
     *
     * @param array $modules The modules list.
     *
     * @throws \Robo\Exception\TaskException
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function componentMandatory($modules)
    {
        $enabledPackages = $mandatoryPackages = [];
        $drushBin = $this->getBin('drush');
        // Check if the website is installed.
        $result = $this->taskExec($drushBin . ' status --format=json')
            ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
            ->run()->getMessage();
        $status = json_decode($result, true);
        if (empty($status['db-name'])) {
            $config_file = $this->getConfig()->get('toolkit.clean.config_file');
            $this->say("Website not installed, using $config_file file.");
            if (file_exists($config_file)) {
                $config = Yaml::parseFile($config_file);
                $enabledPackages = array_keys(array_merge(
                    $config['module'] ?? [],
                    $config['theme'] ?? []
                ));
            } else {
                $this->say("Config file not found at $config_file.");
            }
        } else {
            // Get enabled packages.
            $result = $this->taskExec($drushBin . ' pm-list --fields=status --format=json')
                ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
                ->run()->getMessage();
            $projPackages = json_decode($result, true);
            if (!empty($projPackages)) {
                $enabledPackages = array_keys(array_filter($projPackages, function ($item) {
                    return $item['status'] === 'Enabled';
                }));
            }
        }

        // Get mandatory packages.
        if (!empty($modules)) {
            $mandatoryPackages = array_values(array_filter($modules, function ($item) {
                return $item['mandatory'] === '1';
            }));
        }

        $diffMandatory = array_diff(array_column($mandatoryPackages, 'machine_name'), $enabledPackages);
        if (!empty($diffMandatory)) {
            foreach ($diffMandatory as $notPresent) {
                $index = array_search($notPresent, array_column($mandatoryPackages, 'machine_name'));
                $date = !empty($mandatoryPackages[$index]['mandatory_date']) ? ' (since ' . $mandatoryPackages[$index]['mandatory_date'] . ')' : '';
                echo "Package $notPresent is mandatory$date and is not present on the project." . PHP_EOL;
                $this->mandatoryFailed = true;
            }
        }
        if (!$this->mandatoryFailed) {
            $this->say('Mandatory components check passed.');
        }
    }

    /**
     * Helper function to check component's review information.
     *
     * @param array $modules The modules list.
     * @param array $packages The packages to validate.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function componentRecommended($modules, $packages)
    {
        $recommendedPackages = [];
        // Get project packages.
        $projectPackages = array_column($packages, 'name');
        // Get recommended packages.
        if (!empty($modules)) {
            $recommendedPackages = array_values(array_filter($modules, function ($item) {
                return strtolower($item['usage']) === 'recommended';
            }));
        }

        $diffRecommended = array_diff(array_column($recommendedPackages, 'name'), $projectPackages);
        if (!empty($diffRecommended)) {
            foreach ($diffRecommended as $notPresent) {
                $index = array_search($notPresent, array_column($recommendedPackages, 'name'));
                $date = !empty($recommendedPackages[$index]['mandatory_date']) ? ' (and will be mandatory at ' . $recommendedPackages[$index]['mandatory_date'] . ')' : '';
                echo "Package $notPresent is recommended$date but is not present on the project." . PHP_EOL;
                $this->recommendedFailed = false;
            }
        }
        if (!$this->recommendedFailed) {
            $this->say('This step is in reporting mode, skipping.');
        }
    }

    /**
     * Helper function to check component's review information.
     *
     * @param array $modules The modules list.
     *
     * @throws \Robo\Exception\TaskException
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function componentInsecure($modules)
    {
        $packages = [];
        $drush_result = $this->taskExec($this->getBin('drush') . ' pm:security --format=json')
            ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
            ->run()->getMessage();
        $drush_result = trim($drush_result);
        if (!empty($drush_result) && $drush_result !== '[]') {
            $data = json_decode($drush_result, true);
            if (!empty($data) && is_array($data)) {
                $packages = $data;
            }
        }

        $sc_result = $this->taskExec($this->getBin('security-checker') . ' security:check --no-dev --format=json')
            ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
            ->run()->getMessage();
        $sc_result = trim($sc_result);
        if (!empty($sc_result) && $sc_result !== '[]') {
            $data = json_decode($sc_result, true);
            if (!empty($data) && is_array($data)) {
                $packages = array_merge($packages, $data);
            }
        }

        $messages = [];
        foreach ($packages as $name => $package) {
            $msg = "Package $name has a security update, please update to a safe version.";
            if (!empty($modules[$name]['secure'])) {
                if (Semver::satisfies($package['version'], $modules[$name]['secure'])) {
                    $messages[] = "$msg (Version marked as secure)";
                    continue;
                }
            }
            $historyTerms = $this->getPackageDetails($name, $package['version'], '8.x');
            if (!empty($historyTerms) && (empty($historyTerms['terms']) || !in_array('insecure', $historyTerms['terms']))) {
                $messages[] = "$msg (Confirmation failed, ignored)";
                continue;
            }

            $messages[] = $msg;
            $this->insecureFailed = true;
        }
        if (!empty($messages)) {
            $this->writeln($messages);
        }

        $fullSkip = getenv('QA_SKIP_INSECURE') !== false && getenv('QA_SKIP_INSECURE');
        // Forcing skip due to issues with the security advisor date detection.
        if ($fullSkip) {
            $this->say('Globally skipping security check for components.');
            $this->insecureFailed = false;
        } elseif (!$this->insecureFailed) {
            $this->say('Insecure components check passed.');
        }
    }

    /**
     * Helper function to check component's review information.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function componentOutdated()
    {
        $result = $this->taskExec('composer outdated --direct --minor-only --format=json')
            ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
            ->run()->getMessage();

        $outdatedPackages = json_decode($result, true);

        if (!empty($outdatedPackages['installed'])) {
            if (is_array($outdatedPackages)) {
                foreach ($outdatedPackages['installed'] as $outdatedPackage) {
                    if (!array_key_exists('latest', $outdatedPackage)) {
                        echo "Package {$outdatedPackage['name']} does not provide information about last version." . PHP_EOL;
                    } elseif (array_key_exists('warning', $outdatedPackage)) {
                        echo $outdatedPackage['warning'] . PHP_EOL;
                        $this->outdatedFailed = true;
                    } else {
                        echo "Package {$outdatedPackage['name']} with version installed {$outdatedPackage["version"]} is outdated, please update to last version - {$outdatedPackage['latest']}" . PHP_EOL;
                        $this->outdatedFailed = true;
                    }
                }
            }
        }

        $fullSkip = getenv('QA_SKIP_OUTDATED') !== false && getenv('QA_SKIP_OUTDATED');
        if ($fullSkip) {
            $this->say('Globally skipping outdated check for components.');
            $this->outdatedFailed = false;
        } elseif (!$this->outdatedFailed) {
            $this->say('Outdated components check passed.');
        }
    }

    /**
     * Call release history of d.org to confirm security alert.
     */
    protected function getPackageDetails($package, $version, $core)
    {
        $name = explode('/', $package)[1];
        // Drupal core is an exception, we should use '/drupal/current'.
        if ($package === 'drupal/core') {
            $url = 'https://updates.drupal.org/release-history/drupal/current';
        } else {
            $url = 'https://updates.drupal.org/release-history/' . $name . '/' . $core;
        }

        $releaseHistory = $fullReleaseHistory = [];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $header = ['Content-Type' => 'application/hal+json'];
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

        $result = curl_exec($curl);

        if ($result !== false) {
            $fullReleaseHistory[$name] = simplexml_load_string($result);
            $terms = [];
            foreach ($fullReleaseHistory[$name]->releases as $release) {
                foreach ($release as $releaseItem) {
                    $versionTmp = str_replace($core . '-', '', (string) $releaseItem->version);

                    if (!is_null($version) && Semver::satisfies($versionTmp, $version)) {
                        foreach ($releaseItem->terms as $term) {
                            foreach ($term as $termItem) {
                                $terms[] = strtolower((string) $termItem->value);
                            }
                        }

                        $releaseHistory = [
                            'name' => $name,
                            'version' => (string) $releaseItem->versions,
                            'terms' => $terms,
                            'date' => (string) $releaseItem->date,
                        ];
                    }
                }
            }
            return $releaseHistory;
        }

        $this->say('No release history found.');
        return 1;
    }

    /**
     * Returns a list of packages to test.
     *
     * @return array
     *   An array with packages to test.
     */
    private function testPackages()
    {
        return [
            // Lines below should trow a warning.
            ['type' => 'drupal-module', 'version' => '1.0', 'name' => 'drupal/unreviewed'],
            ['type' => 'drupal-module', 'version' => '1.0', 'name' => 'drupal/devel'],
            ['type' => 'drupal-module', 'version' => '1.0-alpha1', 'name' => 'drupal/xmlsitemap'],
            // Allowed for single project jrc-k4p, otherwise trows warning.
            ['type' => 'drupal-module', 'version' => '1.0', 'name' => 'drupal/active_facet_pills'],
            // Allowed dev version if the Drupal version meets the
            // conflict version constraints.
            [
                'version' => 'dev-1.x',
                'type' => 'drupal-module',
                'name' => 'drupal/views_bulk_operations',
                'extra' => [
                    'drupal' => [
                        'version' => '8.x-3.4+15-dev',
                    ],
                ],
            ],
        ];
    }

}
