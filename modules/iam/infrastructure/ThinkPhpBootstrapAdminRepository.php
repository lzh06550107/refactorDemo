<?php

declare(strict_types=1);

namespace modules\iam\infrastructure;

use modules\iam\contract\BootstrapAdminRepository;
use modules\iam\domain\BootstrapAdminCreateResult;
use RuntimeException;
use think\facade\Db;

final class ThinkPhpBootstrapAdminRepository implements BootstrapAdminRepository
{
    private const LOCK_NAME = 'weplatform:admin-bootstrap';

    public function createFirst(
        string $id,
        string $username,
        string $passwordHash,
    ): BootstrapAdminCreateResult {
        $connection = Db::connect();
        $lockRows = $connection->query(
            "SELECT GET_LOCK('" . self::LOCK_NAME . "', 5) AS acquired",
            [],
            true,
        );

        if ((int) ($lockRows[0]['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Administrator bootstrap lock could not be acquired.');
        }

        try {
            return $connection->transaction(
                static function ($transaction) use ($id, $username, $passwordHash): BootstrapAdminCreateResult {
                    if ((int) $transaction->table('admin_users')->count() > 0) {
                        return BootstrapAdminCreateResult::ALREADY_EXISTS;
                    }

                    $transaction->table('admin_users')->insert([
                        'id' => $id,
                        'username' => $username,
                        'password_hash' => $passwordHash,
                        'status' => 'active',
                        'expires_at' => null,
                        'session_version' => 0,
                    ]);

                    return BootstrapAdminCreateResult::CREATED;
                },
            );
        } finally {
            $connection->query(
                "SELECT RELEASE_LOCK('" . self::LOCK_NAME . "') AS released",
                [],
                true,
            );
        }
    }
}
