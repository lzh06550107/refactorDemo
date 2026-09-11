<?php

declare(strict_types=1);

namespace app\worker\command;

use modules\openplatform\application\AuthorizerProvisioningWorker;
use modules\openplatform\application\ProvisioningBatchRunner;
use modules\openplatform\infrastructure\ThinkPhpProvisioningJobSource;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class OpenPlatformProvisioningWorkerCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('openplatform:provisioning-worker')
            ->setDescription('Process due OpenPlatform authorizer provisioning jobs')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one batch and exit')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum jobs per batch', '100')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Seconds between daemon batches', '2');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $limit = $this->boundedPositiveInt($input->getOption('limit'), 1, 1000, 'limit');
        $sleepSeconds = $this->boundedPositiveInt($input->getOption('sleep'), 1, 60, 'sleep');

        $worker = $this->app->make(AuthorizerProvisioningWorker::class);
        $runner = new ProvisioningBatchRunner(
            new ThinkPhpProvisioningJobSource(),
            static function (string $provisioningId, DateTimeImmutable $now) use ($worker): void {
                $worker->runOne($provisioningId, $now);
            },
        );

        do {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $result = $runner->runBatch($now, $limit);
            $output->writeln(sprintf(
                'provisioning batch discovered=%d handled=%d failed=%d',
                $result->discovered(),
                $result->handled(),
                $result->failed(),
            ));

            if ($once) {
                return $result->failed() === 0 ? 0 : 1;
            }

            sleep($sleepSeconds);
        } while (true);
    }

    private function boundedPositiveInt(mixed $raw, int $min, int $max, string $name): int
    {
        $value = is_int($raw) ? (string) $raw : trim((string) $raw);
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new InvalidArgumentException($name . ' must be a positive integer.');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new InvalidArgumentException($name . ' must be between ' . $min . ' and ' . $max . '.');
        }
        return $number;
    }
}
