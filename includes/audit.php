<?php
/**
 * Log de auditoria: registra quem fez o quê e quando (tela Auditoria, só administrador).
 * Nunca interrompe o fluxo principal: qualquer falha ao gravar o log é ignorada.
 */
function audit(string $action, string $entity = '', ?int $entityId = null, string $details = '', ?int $userId = null, ?string $userName = null): void
{
    try {
        if ($userId === null && ($u = current_user())) {
            $userId = (int)$u['id'];
            $userName = $userName ?? $u['name'];
        }
        q('INSERT INTO audit_log (user_id, user_name, action, entity, entity_id, details, ip) VALUES (?,?,?,?,?,?,?)', [
            $userId, $userName !== null ? mb_substr($userName, 0, 160) : null, mb_substr($action, 0, 40),
            $entity !== '' ? mb_substr($entity, 0, 40) : null, $entityId, $details !== '' ? mb_substr($details, 0, 500) : null,
            mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    } catch (Throwable $e) {
        // auditoria não pode derrubar a operação
    }
}

/** Rótulos amigáveis das ações registradas. */
const AUDIT_ACTIONS = [
    'login' => 'Entrou', 'login_falhou' => 'Falha de login', 'logout' => 'Saiu', 'cadastro_solicitado' => 'Cadastro solicitado',
    'senha_alterada' => 'Senha alterada', 'configuracao' => 'Configurações alteradas',
    'usuario_criado' => 'Usuário criado', 'usuario_editado' => 'Usuário editado', 'usuario_status' => 'Usuário ativado/desativado',
    'usuario_excluido' => 'Usuário excluído', 'cadastro_aprovado' => 'Cadastro aprovado', 'cadastro_recusado' => 'Cadastro recusado',
    'projeto_criado' => 'Projeto criado', 'projeto_editado' => 'Projeto editado', 'projeto_lixeira' => 'Projeto enviado à lixeira',
    'suite_criada' => 'Suíte criada', 'suite_editada' => 'Suíte editada', 'suite_lixeira' => 'Suíte enviada à lixeira',
    'caso_criado' => 'Caso criado', 'caso_editado' => 'Caso editado', 'caso_lixeira' => 'Caso enviado à lixeira',
    'lixeira_restaurar' => 'Restaurado da lixeira', 'lixeira_excluir' => 'Excluído definitivamente', 'lixeira_esvaziar' => 'Lixeira esvaziada',
    'atribuicao' => 'Casos atribuídos', 'atribuicao_removida' => 'Atribuição removida',
    'chamado_decisao' => 'Decisão em chamado', 'chamado_fechado' => 'Chamado fechado',
    'chamado_express' => 'Fluxo Express (automático)', 'caso_express' => 'Modo Express do caso',
    'backup_criado' => 'Backup criado', 'backup_excluido' => 'Backup excluído',
];
