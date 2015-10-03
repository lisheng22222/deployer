<?php

namespace REBELinBLUE\Deployer\Console\Commands;

use Illuminate\Console\Command;
use REBELinBLUE\Deployer\Deployment;

/**
 * A console command for updating the installation.
 */
class UpdateApp extends InstallApp
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Executes any updates needed for the application.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->verifyInstalled() || $this->hasRunningDeployments() || $this->composerOutdated()) {
            return;
        }

        $this->call('down');

        $this->updateConfiguration();
        $this->migrate();
        $this->optimize();
        $this->restartQueue();

        $this->call('up');
    }

    /**
     * Checks for new configuration values in .env.example and copy them to .env.
     * 
     * @return void
     */
    protected function updateConfiguration()
    {
        $config = [];

        // Read the current config values into an array for the writeEnvFile method
        foreach (file(base_path('.env')) as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $parts = explode('=', $line);

            $env = strtolower($parts[0]);
            $value = trim($parts[1]);

            $section = substr($env, 0, strpos($env, '_'));
            $key = substr($env, strpos($env, '_') + 1);

            $config[$section][$key] = $value;
        }

        // Backup the .env file, just in case it failed because we don't want to lose APP_KEY
        copy(base_path('.env'), base_path('.env.prev'));

        // Copy the example file so that new values are copied
        copy(base_path('.env.example'), base_path('.env'));

        // Write the file to disk
        $this->writeEnvFile($config);
    }

    /**
     * Restarts the queues.
     * 
     * @return void
     */
    protected function restartQueue()
    {
        $this->info('Restarting the queue');
        $this->line('');
        $this->call('queue:flush');
        $this->call('queue:restart');
        $this->line('');
    }

    /**
     * Checks if there are any running or pending deployments.
     * 
     * @return boolean
     */
    protected function hasRunningDeployments()
    {
        $deploys = Deployment::whereIn('status', [Deployment::DEPLOYING, Deployment::PENDING])
                             ->count();

        if ($deploys > 0) {
            $this->block([
                'Deployments in progress',
                PHP_EOL,
                'There are still running deployments, please wait for them to finish before updating.',
            ]);

            return true;
        }

        return false;
    }

    /**
     * Check if the composer autoload.php has been updated in the last 10 minutes,
     * if not we assume composer install has not be run recently
     *
     * @return boolean
     */
    protected function composerOutdated()
    {
        if (filemtime(base_path('vendor/autoload.php')) + 600 < time()) {
            $this->block([
                'Update not complete!',
                PHP_EOL,
                'Please run "composer install" before you continue.',
            ]);

            return true;
        }

        return false;
    }

    /**
     * Ensures that Deployer has actually been installed.
     * 
     * @return bool
     */
    private function verifyInstalled()
    {
        if (getenv('APP_KEY') === false || getenv('APP_KEY') === 'SomeRandomString') {
            $this->block([
                'Deployer has not been installed',
                PHP_EOL,
                'Please use "php artisan app:install" instead.',
            ]);

            return false;
        }

        return true;
    }
}