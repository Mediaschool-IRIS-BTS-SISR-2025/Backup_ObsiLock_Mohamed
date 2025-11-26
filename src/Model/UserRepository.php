<?php
namespace App\Model;
use Medoo\Medoo;

class UserRepository
{
    private Medoo $db;

    public function __construct(Medoo $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->get('users', '*', ['id' => $id]) ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->get('users', '*', ['email' => $email]) ?: null;
    }

    public function create(array $data): int
    {
        $this->db->insert('users', $data);
        return (int)$this->db->id();
    }

    public function updateQuota(int $userId, int $newQuota): void
    {
        $this->db->update('users', ['quota_used' => $newQuota], ['id' => $userId]);
    }
}