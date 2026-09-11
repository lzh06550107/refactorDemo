<?php

declare(strict_types=1);

namespace app\worker\command;

use InvalidArgumentException;
use modules\iam\application\BootstrapFirstAdmin;
use modules\iam\application\InitialAdminAlreadyExists;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Throwable;

final class AdminBootstrapCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('admin:bootstrap')
            ->setDescription('Create the initial administrator on an empty installation')
            ->addOption('username', null, Option::VALUE_REQUIRED, 'Initial administrator username');
    }

    protected function execute(Input $input, Output $output): int
    {
        $password = getenv('WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD');
        if ($password === false) {
            if (!$input->isInteractive()) {
                $output->error('WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD is required in non-interactive mode.');
                return 2;
            }

            $password = (string) $output->askHidden($input, 'Initial administrator password: ');
        }

        try {
            $admin = $this->app->make(BootstrapFirstAdmin::class)->execute(
                (string) $input->getOption('username'),
                $password,
            );

            $output->writeln(sprintf(
                'initial administrator created id=%s username=%s',
                $admin->id(),
                $admin->username(),
            ));
            return 0;
        } catch (InitialAdminAlreadyExists|InvalidArgumentException $exception) {
            $output->error($exception->getMessage());
            return 1;
        } catch (Throwable) {
            $output->error('Administrator bootstrap failed.');
            return 1;
        }
    }
}
