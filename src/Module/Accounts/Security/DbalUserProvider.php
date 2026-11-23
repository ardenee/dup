<?php
declare(strict_types=1);
namespace App\Module\Accounts\Security;

use App\Module\Accounts\Domain\Account;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class DbalUserProvider implements UserProviderInterface
{
    public function __construct(private readonly Connection $connection) {}
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, username, password_hash, roles FROM users WHERE username=:name LIMIT 1',
            ['name'=>$identifier]
        );
        if(!$row) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }
        $roles=json_decode((string)$row['roles'],true);
        return new Account((int)$row['id'],(string)$row['username'],
            (string)$row['password_hash'],is_array($roles)?$roles:[]);
    }
    public function refreshUser(UserInterface $user): UserInterface
    {
        if(!$user instanceof Account) throw new UnsupportedUserException();
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }
    public function supportsClass(string $class): bool
    {
        return is_a($class,Account::class,true);
    }
}
