<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\iam\application\AuthenticateAdmin;
use modules\iam\contract\AdminCredentialRepository;
use modules\iam\domain\AdminCredential;
use modules\iam\domain\AdminUser;
use modules\iam\domain\AdminUserStatus;

$now = new DateTimeImmutable('2026-09-11T00:00:00+00:00');

$repositoryFor = static function (?AdminCredential $credential): AdminCredentialRepository {
    return new class($credential) implements AdminCredentialRepository {
        public function __construct(private readonly ?AdminCredential $credential) {}

        public function findByUsername(string $username): ?AdminCredential
        {
            if ($this->credential === null) {
                return null;
            }

            return hash_equals($this->credential->user()->username(), $username)
                ? $this->credential
                : null;
        }
    };
};

$activeUser = new AdminUser('admin-1', 'root', AdminUserStatus::ACTIVE, null);
$activeCredential = new AdminCredential(
    $activeUser,
    password_hash('correct-password', PASSWORD_DEFAULT),
);
$authenticate = new AuthenticateAdmin($repositoryFor($activeCredential));
expectSame(
    $activeUser,
    $authenticate->execute(' root ', 'correct-password', $now),
    'valid credentials authenticate the active administrator',
);

$assertUnauthorized = static function (callable $operation, string $label): void {
    try {
        $operation();
        throw new RuntimeException($label . ' must be rejected');
    } catch (AppException $e) {
        expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), $label . ' error code');
        expectSame(401, $e->httpStatus(), $label . ' http status');
        expectSame('Administrator credentials are invalid.', $e->getMessage(), $label . ' public message');
    }
};

$assertUnauthorized(
    static fn () => (new AuthenticateAdmin($repositoryFor(null)))->execute('missing', 'password', $now),
    'unknown username',
);
$assertUnauthorized(
    static fn () => (new AuthenticateAdmin($repositoryFor($activeCredential)))->execute('root', 'wrong-password', $now),
    'wrong password',
);

$bannedCredential = new AdminCredential(
    new AdminUser('admin-2', 'banned', AdminUserStatus::BANNED, null),
    password_hash('correct-password', PASSWORD_DEFAULT),
);
$assertUnauthorized(
    static fn () => (new AuthenticateAdmin($repositoryFor($bannedCredential)))->execute('banned', 'correct-password', $now),
    'banned administrator',
);

$expiredCredential = new AdminCredential(
    new AdminUser(
        'admin-3',
        'expired',
        AdminUserStatus::ACTIVE,
        new DateTimeImmutable('2026-09-10T23:59:59+00:00'),
    ),
    password_hash('correct-password', PASSWORD_DEFAULT),
);
$assertUnauthorized(
    static fn () => (new AuthenticateAdmin($repositoryFor($expiredCredential)))->execute('expired', 'correct-password', $now),
    'expired administrator',
);

foreach ([
    ['', 'correct-password', 'blank username'],
    ['   ', 'correct-password', 'whitespace username'],
    ['root', '', 'blank password'],
] as [$username, $password, $label]) {
    $assertUnauthorized(
        static fn () => (new AuthenticateAdmin($repositoryFor($activeCredential)))->execute($username, $password, $now),
        $label,
    );
}
