<?php
// Caminho: /core/AuditLogger.php
declare(strict_types=1);

class AuditLogger
{
    public static function log(string $acao, string $modulo, ?string $detalhes = null, ?int $empresaId = null, ?int $usuarioId = null): void
    {
        try {
            $db = Database::getConnection();
            $empId = $empresaId ?? (int)Session::get('empresa_id', 0);
            $usrId = $usuarioId ?? (Session::get('usuario_id') ? (int)Session::get('usuario_id') : null);

            if ($empId <= 0) {
                return;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI', 0, 255);

            $stmt = $db->prepare("
                INSERT INTO logs_auditoria (empresa_id, usuario_id, acao, modulo, detalhes, ip, user_agent)
                VALUES (:empresa_id, :usuario_id, :acao, :modulo, :detalhes, :ip, :user_agent)
            ");
            $stmt->execute([
                ':empresa_id' => $empId,
                ':usuario_id' => $usrId,
                ':acao'       => $acao,
                ':modulo'     => $modulo,
                ':detalhes'   => $detalhes,
                ':ip'         => $ip,
                ':user_agent' => $ua,
            ]);
        } catch (Throwable $e) {
            error_log('Falha ao gravar log de auditoria: ' . $e->getMessage());
        }
    }
}