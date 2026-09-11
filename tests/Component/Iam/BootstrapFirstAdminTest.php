<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use modules\iam\application\BootstrapFirstAdmin;
use modules\iam\application\InitialAdminAlreadyExists;
use modules\iam\contract\AdminIdGenerator;
use modules\iam\contract\BootstrapAdminRepository;
use modules\iam\domain\AdminUserStatus;
use modules\iam\domain\BootstrapAdminCreateResult;

final class FakeBootstrapAdminRepository implements BootstrapAdminRepository
{
    public BootstrapAdminCreateResult $result = BootstrapAdminCreateResult::CREATED;

    /** @var list<array{id:string,username:string,password_hash:string}> */
    public array $calls = [];

    public function createFirst(string $id, string $username, string $passwordHash): BootstrapAdminCreateResult
    {
        $this->calls[] = [
            'id' => $id,
            'username' => $username,
            'password_hash' => $passwordHash,
        ];

        return $this->result;
    }
}

final class FixedAdminIdGenerator implements AdminIdGenerator
{
    public function generate(): string
    {
        return '0123456789abcdef0123456789abcdef';
    }
}

$repository = new FakeBootstrapAdminRepository();
$service = new BootstrapFirstAdmin($repository, new FixedAdminIdGenerator());
$password = '  secret-password-123  ';
$user = $service->execute("  管理员  ", $password);

expectSame('0123456789abcdef0123456789abcdef', $user->id(), 'bootstrap returns generated administrator id');
expectSame('管理员', $user->username(), 'bootstrap trims Unicode username whitespace');
expectSame(AdminUserStatus::ACTIVE, $user->status(), 'bootstrap returns an active administrator');
expectTrue(!$user->isExpired(new DateTimeImmutable('2099-01-01T00:00:00+00:00')), 'bootstrap administrator has no expiry');
expectSame(1, count($repository->calls), 'bootstrap persists exactly one administrator');
expectSame('管理员', $repository->calls[0]['username'], 'repository receives normalized username');
expectTrue($repository->calls[0]['password_hash'] !== $password, 'repository never receives plaintext as the hash');
expectTrue(password_verify($password, $repository->calls[0]['password_hash']), 'password hash preserves all supplied password characters');

$invalidService = static fn (): BootstrapFirstAdmin => new BootstrapFirstAdmin(
    new FakeBootstrapAdminRepository(),
    new FixedAdminIdGenerator(),
);

expectThrows(
    fn () => $invalidService()->execute('ab', 'secret-password-123'),
    InvalidArgumentException::class,
    'two-code-point username is rejected',
);
expectThrows(
    fn () => $invalidService()->execute(str_repeat('a', 65), 'secret-password-123'),
    InvalidArgumentException::class,
    '65-code-point username is rejected',
);
expectThrows(
    fn () => $invalidService()->execute("abc\x1F", 'secret-password-123'),
    InvalidArgumentException::class,
    'ASCII control character in username is rejected',
);
expectThrows(
    fn () => $invalidService()->execute("abc\x7F", 'secret-password-123'),
    InvalidArgumentException::class,
    'DEL in username is rejected',
);
expectThrows(
    fn () => $invalidService()->execute('valid-admin', '12345678901'),
    InvalidArgumentException::class,
    '11-code-point password is rejected',
);
expectThrows(
    fn () => $invalidService()->execute('valid-admin', str_repeat('密', 342)),
    InvalidArgumentException::class,
    'password over 1024 UTF-8 bytes is rejected',
);

$alreadyExistsRepository = new FakeBootstrapAdminRepository();
$alreadyExistsRepository->result = BootstrapAdminCreateResult::ALREADY_EXISTS;
$alreadyExists = new BootstrapFirstAdmin($alreadyExistsRepository, new FixedAdminIdGenerator());
expectThrows(
    fn () => $alreadyExists->execute('valid-admin', 'secret-password-123'),
    InitialAdminAlreadyExists::class,
    'existing administrator blocks initial bootstrap',
);
