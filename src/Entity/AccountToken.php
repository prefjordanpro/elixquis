<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AccountToken
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;
    #[ORM\Column(length: 16)]
    private string $purpose;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;
    #[ORM\Column(length: 64)]
    private string $identityHash;

    public function __construct(User $user, string $raw, string $purpose)
    {
        $this->user = $user; $this->purpose = $purpose;
        $this->tokenHash = hash('sha256', $raw);
        $this->expiresAt = new \DateTimeImmutable($purpose === 'reset' ? '+1 hour' : '+24 hours');
        $this->identityHash = self::identity($user, $purpose);
    }
    private static function identity(User $user, string $purpose): string
    {
        return hash('sha256', $user->getEmail().($purpose === 'reset' ? $user->getPassword() : ''));
    }
    public function getUser(): User { return $this->user; }
    public function isValid(): bool
    {
        return $this->expiresAt > new \DateTimeImmutable()
            && hash_equals($this->identityHash, self::identity($this->user, $this->purpose));
    }
}
