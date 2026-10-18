<?php
// Caminho: /core/Security.php
declare(strict_types=1);

class Security
{
    // Escape seguro contra XSS
    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // Gera ou retorna o token CSRF da sessão atual
    public static function generateCsrfToken(): string
    {
        Session::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // Renderiza o input oculto de CSRF para formulários
    public static function csrfField(): string
    {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . self::e($token) . '">';
    }

    // Valida o token CSRF recebido no POST
    public static function validateCsrf(?string $token): bool
    {
        Session::start();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // Formata moeda para padrão brasileiro R$
    public static function formatMoney(float|int|string $value): string
    {
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }

    // Formata data YYYY-MM-DD para DD/MM/YYYY
    public static function formatDate(?string $date): string
    {
        if (empty($date)) {
            return '—';
        }
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : '—';
    }
}