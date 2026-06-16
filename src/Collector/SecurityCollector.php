<?php

namespace MySQLTuner\Collector;

use PDO;

class SecurityCollector implements CollectorInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function collect(): array
    {
        return [
            'anonymous_users' => $this->countAnonymousUsers(),
            'empty_password_users' => $this->countEmptyPasswordUsers(),
            'users_without_host' => $this->countUsersWithoutHost(),
            'test_database_exists' => $this->testDatabaseExists(),
            'have_root_empty_password' => $this->rootHasEmptyPassword(),
        ];
    }

    private function countAnonymousUsers(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as cnt FROM mysql.user WHERE user = ''");
            return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        } catch (\PDOException) {
            return -1;
        }
    }

    private function countEmptyPasswordUsers(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as cnt FROM mysql.user WHERE authentication_string = '' OR password = ''");
            return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        } catch (\PDOException) {
            return -1;
        }
    }

    private function countUsersWithoutHost(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as cnt FROM mysql.user WHERE host = '%'");
            return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        } catch (\PDOException) {
            return -1;
        }
    }

    private function testDatabaseExists(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'test'");
            return (bool) $stmt->fetch();
        } catch (\PDOException) {
            return false;
        }
    }

    private function rootHasEmptyPassword(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as cnt FROM mysql.user WHERE user = 'root' AND (authentication_string = '' OR authentication_string IS NULL)");
            return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
        } catch (\PDOException) {
            return false;
        }
    }
}
