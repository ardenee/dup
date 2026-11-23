<?php
declare(strict_types=1);
namespace App\Module\Accounts\Domain;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class Account implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        public readonly int $id,
        private readonly string $username,
        private readonly string $passwordHash,
        private readonly array $roles = ['ROLE_ADMIN']
    ) {}
    public function getUserIdentifier(): string { return $this->username; }
    public function getPassword(): string { return $this->passwordHash; }
    public function getRoles(): array { return array_unique([...$this->roles,'ROLE_USER']); }
    public function eraseCredentials(): void {}
}
